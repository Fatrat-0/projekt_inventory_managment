<?php

namespace App\Support;

use App\Models\{WarehouseLocation, WarehousePlacement, WarehouseStorageSystem};
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;

class WarehouseStorageOverview
{
    public static function defaultBinDimensions(WarehouseStorageSystem $system, int $bins, int $levels): array
    {
        return ['width_cm' => intdiv($system->frontWidth(), max(1, $bins)), 'depth_cm' => $system->depth(),
            'height_cm' => $system->height_cm === null ? 100 : intdiv($system->height_cm, max(1, $levels))];
    }

    public static function products(int $warehouseId, ?int $systemId = null, ?array $locationIds = null): Collection
    {
        return WarehousePlacement::query()
            ->join('warehouse_locations', 'warehouse_locations.id', '=', 'warehouse_placements.warehouse_location_id')
            ->join('stocks', 'stocks.id', '=', 'warehouse_placements.stock_id')
            ->join('products', 'products.product_id', '=', 'stocks.product_id')
            ->where('warehouse_locations.warehouse_id', $warehouseId)
            ->when($systemId !== null, fn ($query) => $query->where('warehouse_locations.system_id', $systemId))
            ->when($locationIds !== null, fn ($query) => $query->whereIntegerInRaw('warehouse_locations.id', $locationIds))
            ->select('warehouse_placements.warehouse_location_id', 'products.product_id', 'products.product_name', 'products.sku')
            ->selectRaw('SUM(warehouse_placements.quantity) as quantity')
            ->groupBy('warehouse_placements.warehouse_location_id', 'products.product_id', 'products.product_name', 'products.sku')
            ->orderBy('products.product_name')->orderBy('products.product_id')->toBase()->get()->groupBy('warehouse_location_id');
    }

    public static function location(WarehouseLocation $location, Collection $products): array
    {
        return ['id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'system_id' => $location->system_id,
            'level_id' => $location->level_id, 'active' => $location->active,
            'limit' => $location->max_weight_grams, 'free' => $location->active ? max(0, $location->freeWeight()) : 0,
            'occupied' => (int) $location->occupied_weight, 'reserved' => (int) $location->reserved_weight,
            'maxSlots' => $location->max_pallet_slots, 'freeSlots' => $location->active ? $location->freeSlots() : ($location->max_pallet_slots === null ? null : 0),
            'occupiedSlots' => (int) $location->occupied_slots, 'reservedSlots' => (int) $location->reserved_slots,
            'units' => (int) $products->sum('quantity')];
    }

    public static function tone(array $capacity): string
    {
        if (!$capacity['active']) return 'blocked';
        if ($capacity['free'] <= 0 || $capacity['freeSlots'] === 0) return 'full';

        return max(self::utilization($capacity)) >= 0.8 ? 'near' : 'available';
    }

    public static function utilization(array $capacity): array
    {
        $committed = $capacity['occupied'] + $capacity['reserved'];
        $usable = $committed + $capacity['free'];
        $committedSlots = $capacity['occupiedSlots'] + $capacity['reservedSlots'];
        $usableSlots = $committedSlots + ($capacity['freeSlots'] ?? 0);

        return ['weight' => $usable > 0 ? $committed / $usable : 0,
            'slots' => $capacity['maxSlots'] === null ? null : ($usableSlots > 0 ? $committedSlots / $usableSlots : 0)];
    }

    public static function systemSummaries(ModelCollection $systems): Collection
    {
        if ($systems->isEmpty()) return collect();

        $bins = WarehouseLocation::whereIntegerInRaw('system_id', $systems->modelKeys())
            ->select(['id', 'code', 'name', 'system_id', 'level_id', 'active', 'max_weight_grams', 'max_pallet_slots'])
            ->withCapacityUsage()->get()->toBase()->groupBy('system_id');

        return $systems->toBase()->mapWithKeys(function (WarehouseStorageSystem $system) use ($bins) {
            $data = self::system($system, $bins->get($system->id, collect())->map(fn ($bin) => self::location($bin, collect())));
            unset($data['levels']);

            return [$system->id => $data + ['utilization' => self::utilization($data), 'tone' => self::tone($data)]];
        });
    }

    public const STATES = ['blocked' => 'Kikapcsolt', 'full' => 'Betelt', 'near' => 'Közel telített', 'available' => 'Van szabad kapacitás'];

    public static function locationFreeWeights(ModelCollection $locations, bool $allBinsLoaded = false): array
    {
        $managed = $locations->whereNotNull('system_id');
        if ($managed->isEmpty()) return [];

        $managed->loadMissing(['system:id,max_weight_grams', 'level:id,max_weight_grams']);
        $bins = $allBinsLoaded ? $managed : WarehouseLocation::withCapacityUsage()->whereIn('system_id', $managed->pluck('system_id')->unique())->get();
        $committed = fn ($group) => $group->sum(fn ($bin) => (int) $bin->occupied_weight + (int) $bin->reserved_weight);
        $bySystem = $bins->groupBy('system_id')->map($committed);
        $byLevel = $bins->whereNotNull('level_id')->groupBy('level_id')->map($committed);

        return $managed->mapWithKeys(function ($bin) use ($bySystem, $byLevel) {
            $systemFree = $bin->system->max_weight_grams - $bySystem[$bin->system_id];
            $levelFree = $bin->level ? $bin->level->max_weight_grams - $byLevel[$bin->level_id] : $systemFree;

            return [$bin->id => $bin->active ? max(0, min($bin->freeWeight(), $systemFree, $levelFree)) : 0];
        })->all();
    }

    public static function system(WarehouseStorageSystem $system, Collection $bins): array
    {
        $groups = $system->kind === 'floor'
            ? collect([['name' => 'Padlóhelyek', 'limit' => $system->max_weight_grams, 'bins' => $bins]])
            : $system->levels->map(fn ($level) => ['name' => $level->number.'. szint', 'limit' => $level->max_weight_grams,
                'bins' => $bins->where('level_id', $level->id)]);
        $levels = $groups->map(fn ($group) => self::capacity($group['bins'], $group['limit']) + ['name' => $group['name'], 'bins' => $group['bins']]);
        $capacity = self::capacity($bins, $system->max_weight_grams);
        $capacity['free'] = min($capacity['free'], $levels->sum('free'));

        return $capacity + [
            'id' => $system->id, 'name' => $system->name, 'code' => $system->displayCode(), 'zone' => $system->zone->name, 'kind' => $system->kind,
            'kindLabel' => WarehouseStorageSystem::KINDS[$system->kind], 'x' => $system->floor_x, 'y' => $system->floor_y,
            'width' => $system->floor_width, 'height' => $system->floor_height, 'fixedSize' => $system->front_width_cm !== null,
            'levelWeight' => ($system->levels->first()?->max_weight_grams ?? 0) / 1000, 'systemWeight' => $system->max_weight_grams / 1000,
            'rackHeight' => $system->height_cm === null ? null : $system->height_cm / 100,
            'levels' => $levels->map(function ($level) use ($capacity) {
                $level['free'] = min($level['free'], $capacity['free']);
                $level['bins'] = $level['bins']->map(function ($bin) use ($level) {
                    $bin['free'] = min($bin['free'], $level['free']);

                    return $bin;
                })->values();

                return $level;
            })->values(),
        ];
    }

    private static function capacity(Collection $bins, int $limit): array
    {
        $occupied = (int) $bins->sum('occupied');
        $reserved = (int) $bins->sum('reserved');
        $active = $bins->where('active', true);
        $pallet = $bins->contains(fn ($bin) => $bin['maxSlots'] !== null);

        return ['active' => $active->isNotEmpty(), 'limit' => $limit, 'occupied' => $occupied, 'reserved' => $reserved, 'units' => (int) $bins->sum('units'),
            'free' => min(max(0, $limit - $occupied - $reserved), $active->sum('free')),
            'maxSlots' => $pallet ? $bins->sum('maxSlots') : null, 'occupiedSlots' => $bins->sum('occupiedSlots'),
            'reservedSlots' => $bins->sum('reservedSlots'), 'freeSlots' => $pallet ? $active->sum('freeSlots') : null];
    }
}
