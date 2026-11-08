<?php

namespace App\Services;

use App\Models\{Warehouse, WarehouseLocation, WarehouseStorageSystem, WarehouseStorageZone};
use App\Support\WarehouseStorageOverview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseStorageDesigner
{
    public function __construct(private WarehouseFloorPlan $plan, private WarehouseCapacity $capacity) {}

    public function create(Warehouse $warehouse, array $data): array
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $current = $this->plan->lockVersion($warehouse, (int) $data['version']);
            $floor = $data['kind'] === 'floor';
            [$binLimit, $levelLimit, $systemLimit] = $this->limits($data, $floor);
            $geometry = WarehouseFloorPlan::geometry($data);
            $keys = array_keys($geometry);
            $occupied = [];
            foreach ($current->storageLocations()->whereNull('system_id')->whereNotNull('floor_x')->get() as $location) {
                WarehouseFloorPlan::claim($location->only($keys), $location->code, $current->floor_columns, $current->floor_rows, $occupied);
            }
            foreach ($current->storageSystems()->get() as $system) {
                WarehouseFloorPlan::claim($system->only($keys), $system->name, $current->floor_columns, $current->floor_rows, $occupied);
            }
            WarehouseFloorPlan::claim($geometry, $data['name'], $current->floor_columns, $current->floor_rows, $occupied);

            $code = isset($data['code']) ? strtoupper($data['code']) : null;
            if ($code !== null && $current->storageSystems()->where('warehouse_storage_systems.code', $code)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['code' => 'Ez a tárolórendszer-azonosító már szerepel a raktárban.']);
            }
            $zone = WarehouseStorageZone::firstOrCreate(['warehouse_id' => $current->getKey(), 'name' => $data['zone']]);
            $rotated = (bool) ($data['rotated'] ?? false);
            $system = $zone->systems()->create($geometry + [
                'name' => $data['name'], 'code' => $code, 'kind' => $data['kind'], 'max_weight_grams' => $systemLimit,
                'height_cm' => $floor ? null : (int) round((float) $data['rack_height'] * 100),
                'front_width_cm' => $geometry[$rotated ? 'floor_height' : 'floor_width'] * 50,
                'depth_cm' => $geometry[$rotated ? 'floor_width' : 'floor_height'] * 50,
            ]);
            $prefix = $system->displayCode();
            $levelCount = $floor ? 1 : (int) $data['levels'];
            $binCount = (int) $data['bins'];
            $levelWeights = $floor ? [] : ($data['level_weights'] ?? []);
            foreach (array_keys($levelWeights) as $number) {
                if (!is_int($number) || $number < 1 || $number > $levelCount) {
                    throw ValidationException::withMessages(['level_weights' => 'Csak létező szinthez adhatsz meg külön teherbírást.']);
                }
            }
            $dimensions = ($data['bin_dimensions'] ?? []) + WarehouseStorageOverview::defaultBinDimensions($system, $binCount, $levelCount);
            $this->assertDimensions($system, collect(array_fill(0, $levelCount, collect(array_fill(0, $binCount, $dimensions)))));
            $codes = fn (string $prefix) => collect(range(1, $levelCount))->flatMap(fn ($level) => array_map(
                fn ($bin) => $code === null
                    ? ($floor ? sprintf('%s-H%02d', $prefix, $bin) : sprintf('%s-S%02d-H%02d', $prefix, $level, $bin))
                    : ($floor ? sprintf('%s-%02d', $prefix, $bin) : sprintf('%s-%02d-%02d', $prefix, $level, $bin)),
                range(1, $binCount)))->all();
            for ($suffix = 1; $current->storageLocations()->whereIn('code', $codes($prefix))->lockForUpdate()->first(['id']); $suffix++) {
                if ($code !== null) throw ValidationException::withMessages(['code' => 'A megadott azonosítóval létrejövő tárhelykód már használatban van.']);
                $prefix = 'T'.$system->id.'X'.$suffix;
            }
            $codes = $codes($prefix);
            $pallet = $data['kind'] !== 'rack';
            for ($number = 1; $number <= $levelCount; $number++) {
                $level = $floor ? null : $system->levels()->create(['number' => $number,
                    'max_weight_grams' => isset($levelWeights[$number]) ? WarehouseCapacity::grams($levelWeights[$number]) : $levelLimit]);
                for ($bin = 1; $bin <= $binCount; $bin++) {
                    $binCode = $codes[($number - 1) * $binCount + $bin - 1];
                    $name = $floor ? $bin.'. padlóhely' : sprintf('%d. szint · %d. %s', $number, $bin, $pallet ? 'raklaphely' : 'rekesz');
                    $location = $current->storageLocations()->make(['code' => $binCode, 'name' => $name,
                        'kind' => $pallet ? 'pallet' : 'shelf', 'max_weight_grams' => $binLimit, 'max_pallet_slots' => $pallet ? 1 : null]);
                    $location->forceFill($dimensions + ['system_id' => $system->id, 'level_id' => $level?->id, 'bin_number' => $bin])->save();
                }
            }
            $current->increment('floor_version');

            return ['id' => $system->id, 'version' => (int) $current->floor_version];
        }, 3);
    }

    public function update(Warehouse $warehouse, int $systemId, array $data): void
    {
        DB::transaction(function () use ($warehouse, $systemId, $data) {
            $current = $this->plan->lockVersion($warehouse, (int) $data['version']);
            $system = $current->storageSystems()->findOrFail($systemId);
            $floor = $system->kind === 'floor';
            [$binLimit, $levelLimit, $systemLimit] = $this->limits($data, $floor);
            $bins = $system->locations()->lockForUpdate()->get();
            $committed = $this->capacity->committedWeights($bins->modelKeys());
            foreach ($bins as $bin) {
                if ($binLimit !== null && ($committed[$bin->id] ?? 0) > $binLimit) {
                    throw ValidationException::withMessages(['bin_weight' => 'A(z) '.$bin->code.' már tárolt és lefoglalt tömege meghaladja az új helyenkénti határt.']);
                }
            }
            foreach ($system->levels as $level) {
                if ($levelLimit !== null && $bins->where('level_id', $level->id)->sum(fn ($bin) => $committed[$bin->id] ?? 0) > $levelLimit) {
                    throw ValidationException::withMessages(['level_weight' => 'A(z) '.$level->number.'. szint terhelése meghaladja az új határt.']);
                }
            }
            if (array_sum($committed) > $systemLimit) {
                throw ValidationException::withMessages(['system_weight' => 'A tárolt és lefoglalt tömeg meghaladja az új összesített határt.']);
            }
            $zone = WarehouseStorageZone::firstOrCreate(['warehouse_id' => $current->getKey(), 'name' => $data['zone']]);
            $system->fill(['zone_id' => $zone->id, 'name' => $data['name'], 'max_weight_grams' => $systemLimit,
                'height_cm' => !$floor && isset($data['rack_height']) ? (int) round((float) $data['rack_height'] * 100) : $system->height_cm]);
            if ($system->isDirty('height_cm')) $this->assertDimensions($system, $this->dimensionsByLevel($system, $bins));
            $system->save();
            if ($levelLimit !== null) $system->levels()->update(['max_weight_grams' => $levelLimit]);
            if ($binLimit !== null) {
                $system->locations()->where('max_weight_grams', '<>', $binLimit)
                    ->update(['max_weight_grams' => $binLimit, 'lock_version' => DB::raw('lock_version + 1')]);
            }
            $current->increment('floor_version');
        }, 3);
    }

    public function updateBin(Warehouse $warehouse, int $systemId, int $locationId, array $data): void
    {
        DB::transaction(function () use ($warehouse, $systemId, $locationId, $data) {
            $this->capacity->lockWarehouses([$warehouse->getKey()]);
            $system = $warehouse->storageSystems()->findOrFail($systemId);
            $bins = $system->locations()->lockForUpdate()->get()->keyBy('id');
            $bin = $bins->get($locationId);
            abort_unless($bin, 404);
            if ($bin->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'A rekeszt közben módosították. Töltsd újra az oldalt.'])->status(409);
            }
            $limit = WarehouseCapacity::grams($data['max_weight_kg']);
            $committed = $this->capacity->committedWeights([$bin->id])[$bin->id] ?? 0;
            if ($committed > $limit || ($committed > 0 && !$data['active'])) {
                throw ValidationException::withMessages(['max_weight_kg' => 'A tárolt és lefoglalt tételek mellett a rekesz nem kapcsolható ki és a határ nem csökkenthető a lekötött tömeg alá.']);
            }
            $categories = array_map('intval', $data['categories'] ?? []);
            $this->capacity->assertExistingCategories($bin, $categories);
            $bin->forceFill(collect($data)->only(['width_cm', 'depth_cm', 'height_cm', 'active'])->all()
                + ['max_weight_grams' => $limit, 'lock_version' => $bin->lock_version + 1]);
            $this->assertDimensions($system, $this->dimensionsByLevel($system, $bins));
            $system->update(['front_width_cm' => $system->frontWidth(), 'depth_cm' => $system->depth()]);
            $bin->save();
            $bin->allowedCategories()->sync($categories);
        }, 3);
    }

    private function dimensionsByLevel(WarehouseStorageSystem $system, Collection $bins): Collection
    {
        $levels = $bins->groupBy('level_id');

        return $levels->map(fn ($group) => $group->map(fn (WarehouseLocation $bin) =>
            array_filter($bin->only(['width_cm', 'depth_cm', 'height_cm']), fn ($value) => $value !== null)
            + WarehouseStorageOverview::defaultBinDimensions($system, $group->count(), $levels->count())));
    }

    private function assertDimensions(WarehouseStorageSystem $system, Collection $levels): void
    {
        foreach ($levels as $level) {
            if ($level->sum('width_cm') > $system->frontWidth() || $level->max('depth_cm') > $system->depth()) {
                throw ValidationException::withMessages(['bin_dimensions' => 'A rekeszek együttes szélessége vagy egy rekesz mélysége meghaladja a tárolórendszer méretét.']);
            }
        }
        if ($system->height_cm !== null && $levels->sum(fn ($level) => $level->max('height_cm')) > $system->height_cm) {
            throw ValidationException::withMessages(['bin_dimensions' => 'A szintek legmagasabb rekeszei együtt magasabbak az állványnál.']);
        }
    }

    private function limits(array $data, bool $floor): array
    {
        $bin = isset($data['bin_weight']) ? WarehouseCapacity::grams($data['bin_weight']) : null;
        $system = WarehouseCapacity::grams($data['system_weight']);
        $level = $floor ? $system : (isset($data['level_weight']) ? WarehouseCapacity::grams($data['level_weight']) : null);
        return [$bin, $level, $system];
    }
}
