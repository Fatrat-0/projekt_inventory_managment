<?php

namespace App\Support;

use App\Models\{WarehouseLoadVerification, WarehouseLocation, WarehousePlacement};
use Illuminate\Support\Collection;

final class WarehouseLoadDiscrepancies
{
    private const REASONS = [
        'location' => 'A rekeszben nyitott terhelési eltérés van. Új áru csak az eltérés rendezése után helyezhető el.',
        'level' => 'Ezen a polcszinten nyitott terhelési eltérés van. Új áru csak az eltérés rendezése után helyezhető el.',
        'system' => 'Ebben a tárolórendszerben nyitott terhelési eltérés van. Új áru csak az eltérés rendezése után helyezhető el.',
    ];

    public static function read(array $binIds, bool $locked = false): Collection
    {
        return WarehouseLoadVerification::whereIntegerInRaw('location_id', $binIds)->openDiscrepancies($locked)
            ->when($locked, fn ($query) => $query->sharedLock())
            ->with(['placement' => fn ($query) => $query->when($locked, fn ($rows) => $rows->sharedLock())])
            ->orderBy('id')->get();
    }

    /**
     * One documented reading per open placement. Keep the higher of the recorded and measured
     * load while open. A measurement of an earlier quantity provides no usable headroom.
     */
    public static function summarize(
        Collection $bins, ?Collection $events = null, ?int $excludedPlacement = null, array $changedPlacements = [],
    ): array {
        $locations = $bins->keyBy('id');
        $events ??= $bins->flatMap(fn (WarehouseLocation $bin) => $bin->openLoadDiscrepancies);
        $result = ['locations' => [], 'levels' => [], 'systems' => [], 'critical' => []];
        foreach ($events as $event) {
            if ($excludedPlacement === (int) $event->placement_id) continue;
            $bin = $locations->get($event->location_id);
            $placement = $event->placement;
            $stale = in_array((int) $event->placement_id, $changedPlacements, true) || !self::isCurrent($event, $placement);
            $location = $result['locations'][$bin->id] ?? ['weight' => 0, 'slots' => 0];
            if ($placement) {
                $location['weight'] += max(0, $event->measured_weight_grams - $placement->cargo_weight_grams);
                $location['slots'] += max(0, $event->measured_pallet_slots - $placement->pallet_slots);
            }
            $result['locations'][$bin->id] = $location;
            $violations = $event->capacity_violations;
            if (!$stale) {
                $result['critical']['location'][$bin->id] = true;
                if ($bin->level_id !== null && in_array('level_weight', $violations, true)) {
                    $result['critical']['level'][$bin->level_id] = true;
                }
                if ($bin->system_id !== null && in_array('system_weight', $violations, true)) {
                    $result['critical']['system'][$bin->system_id] = true;
                }
            }
            if ($bin->level_id !== null && ($stale || in_array('level_weight', $violations, true))) {
                $result['levels'][$bin->level_id] = true;
            }
            if ($bin->system_id !== null && ($stale || in_array('system_weight', $violations, true))) {
                $result['systems'][$bin->system_id] = true;
            }
        }

        return $result;
    }

    public static function isCurrent(WarehouseLoadVerification $event, ?WarehousePlacement $placement): bool
    {
        return $placement !== null && (int) $placement->warehouse_location_id === (int) $event->location_id
            && $placement->quantity === $event->quantity && $placement->load_requires_verification
            && $placement->cargo_weight_grams === $event->previous_weight_grams
            && $placement->pallet_slots === $event->previous_pallet_slots;
    }

    public static function severity(WarehouseLocation $bin, array $usage): ?string
    {
        if (isset($usage['critical']['location'][$bin->id]) || isset($usage['critical']['level'][$bin->level_id])
            || isset($usage['critical']['system'][$bin->system_id])) {
            return 'critical';
        }

        return self::blockers($bin, $usage) ? 'warning' : null;
    }

    public static function blockers(WarehouseLocation $bin, array $usage): array
    {
        $blocked = [];
        if (isset($usage['locations'][$bin->id])) $blocked['location_discrepancy'] = self::REASONS['location'];
        if (isset($usage['levels'][$bin->level_id])) $blocked['level_discrepancy'] = self::REASONS['level'];
        if (isset($usage['systems'][$bin->system_id])) $blocked['system_discrepancy'] = self::REASONS['system'];

        return $blocked;
    }
}
