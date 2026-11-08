<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseFloorPlan
{
    public const MIN_SIZE = 4;
    public const MAX_SIZE = 100;

    public function lockVersion(Warehouse $warehouse, int $version): Warehouse
    {
        $current = Warehouse::whereKey($warehouse->getKey())->lockForUpdate()->firstOrFail();
        if ((int) $current->floor_version !== $version) {
            throw ValidationException::withMessages(['version' => 'A tervrajzot közben módosították. Töltsd újra az oldalt a mentett változat megtekintéséhez.'])->status(409);
        }

        return $current;
    }

    public static function geometry(array $position): array
    {
        return ['floor_x' => (int) $position['x'], 'floor_y' => (int) $position['y'],
            'floor_width' => (int) $position['width'], 'floor_height' => (int) $position['height']];
    }

    public static function claim(array $geometry, string $name, int $columns, int $rows, array &$occupied): void
    {
        ['floor_x' => $x, 'floor_y' => $y, 'floor_width' => $width, 'floor_height' => $height] = $geometry;
        if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $columns || $y + $height > $rows) {
            throw ValidationException::withMessages(['positions' => 'A(z) '.$name.' kilóg a tervrajzból.']);
        }

        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) {
                $cell = $row * $columns + $column;
                if (isset($occupied[$cell])) {
                    throw ValidationException::withMessages(['positions' => 'A(z) '.$name.' és a(z) '.$occupied[$cell].' átfedi egymást.']);
                }
                $occupied[$cell] = $name;
            }
        }
    }

    public function save(Warehouse $warehouse, array $data): int
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $current = $this->lockVersion($warehouse, (int) $data['version']);
            $columns = (int) $data['columns'];
            $rows = (int) $data['rows'];
            $locations = $current->storageLocations()->whereNull('system_id')->lockForUpdate()->get()->keyBy('id');
            $systems = $current->storageSystems()->get()->keyBy('id');
            $positions = $systemPositions = $occupied = [];
            foreach ($data['positions'] as $position) {
                $id = (int) $position['id'];
                if (!$locations->has($id) || isset($positions[$id])) {
                    throw ValidationException::withMessages(['positions' => 'Minden önálló tárhely egyszer szerepelhet, és ehhez a raktárhoz kell tartoznia.']);
                }
                $positions[$id] = self::geometry($position);
                self::claim($positions[$id], $locations[$id]->code.' tárhely', $columns, $rows, $occupied);
            }

            foreach ($data['systems'] ?? [] as $position) {
                $id = (int) $position['id'];
                if (!$systems->has($id) || isset($systemPositions[$id])) {
                    throw ValidationException::withMessages(['systems' => 'Minden tárolórendszer egyszer szerepelhet, és ehhez a raktárhoz kell tartoznia.']);
                }
                $systemPositions[$id] = self::geometry($position);
            }

            $changed = false;
            foreach ($systems as $id => $system) {
                $system->fill($systemPositions[$id] ?? []);
                if ($system->front_width_cm !== null) {
                    $actual = [$system->floor_width * 50, $system->floor_height * 50];
                    $size = [$system->frontWidth(), $system->depth()];
                    if ($actual !== $size && $actual !== array_reverse($size)) {
                        throw ValidationException::withMessages(['systems' => 'A fizikai tárolórendszer mozgatható és forgatható. A tervrajzi méretének meg kell egyeznie a tárolórendszer méretével.']);
                    }
                }
                self::claim($system->only(['floor_x', 'floor_y', 'floor_width', 'floor_height']), $system->name, $columns, $rows, $occupied);
                if (!$system->isDirty()) continue;
                $system->save();
                $changed = true;
            }

            $unplaced = array_fill_keys(['floor_x', 'floor_y', 'floor_width', 'floor_height'], null);
            foreach ($locations as $id => $location) {
                $location->forceFill($positions[$id] ?? $unplaced);
                if (!$location->isDirty()) continue;
                $location->save();
                $changed = true;
            }

            $current->forceFill(['floor_columns' => $columns, 'floor_rows' => $rows]);
            if ($changed || $current->isDirty()) {
                $current->floor_version++;
                $current->save();
            }

            return (int) $current->floor_version;
        }, 3);
    }
}
