<?php

namespace App\Services;

use App\Models\{Product, Warehouse, WarehouseLocation};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;

final class SmartPutaway
{

    public function __construct(private WarehouseCapacity $capacity) {}

    /**
     * Read-only advice for one unsplit cargo line in its intended orientation.
     * Quantity counts product units across the whole line. Weight is the line's total
     * gross weight in kg. Pallet slots are null when unknown or 0 for non-pallet cargo.
     *
     * Dimensions in cm describe the outer envelope of the entire load as it will be
     * stored, including its packaging. For multiple boxes, measure their actual
     * arrangement. When the arrangement is unknown, leave dimensions unset for
     * conditional advice. Separate box queries are independent capacity snapshots.
     * The returned capacity is a snapshot. Placement and reservation must revalidate it.
     *
     * Each candidate has a location ID, code, name, kind and status. Checks contain
     * passed rule IDs and maps of missing or failed rule IDs to Hungarian explanations.
     * Up to five eligible candidates are returned, with verified candidates ahead
     * of conditional candidates. Each includes its score and explanations.
     * Rejected locations are reported separately, within the requested warehouse only.
     *
     * @param array{quantity: int, cargo_weight_kg?: numeric|null, pallet_slots?: int|null, dimensions_cm?: array{width?: int|null, depth?: int|null, height?: int|null}|null} $cargo
     * @return array{warehouse_id: int, product_id: int, quantity: int, recommendations: list<array>, rejected: list<array>}
     */
    public function recommend(Warehouse $warehouse, Product $product, array $cargo): array
    {
        $data = $this->validatedCargo($cargo);
        $candidates = $this->candidates($warehouse, $product, $data);
        $recommendations = array_values(array_filter($candidates, fn (array $candidate) => $candidate['status'] !== 'rejected'));
        $rejected = array_values(array_filter($candidates, fn (array $candidate) => $candidate['status'] === 'rejected'));
        usort($recommendations, fn (array $a, array $b) => PutawayRanking::compare($a['score'], $b['score'], $a['code'], $b['code']));

        return ['warehouse_id' => (int) $warehouse->getKey(), 'product_id' => (int) $product->getKey(), 'quantity' => (int) $data['quantity'],
            'recommendations' => array_slice($recommendations, 0, 5), 'rejected' => $rejected];
    }

    /**
     * Read the matching candidates, including rejected ones, in code order.
     * Location filters do not exclude other bins from shared capacity totals.
     *
     * @return list<array>
     */
    public function assessLocations(Warehouse $warehouse, Product $product, array $cargo, ?int $locationId = null, ?string $search = null): array
    {
        return $this->candidates($warehouse, $product, $this->validatedCargo($cargo), $locationId, $search);
    }

    private function validatedCargo(array $cargo): array
    {
        return Validator::make($cargo, [
            'quantity' => 'required|integer|min:1|max:2147483647',
            'cargo_weight_kg' => 'nullable|'.WarehouseCapacity::WEIGHT_RULE,
            'pallet_slots' => 'nullable|integer|min:0|max:1000000',
            'dimensions_cm' => 'nullable|array:width,depth,height',
            'dimensions_cm.*' => 'nullable|integer|min:1|max:65535',
        ], [
            'quantity.*' => 'A mennyiség pozitív egész szám legyen, legfeljebb 2 147 483 647.',
            'cargo_weight_kg.*' => 'A rakomány tömege 0,001 és 1 000 000 kg közötti érték legyen, legfeljebb három tizedesjeggyel.',
            'pallet_slots.*' => 'A szükséges raklaphelyek száma 0 és 1 000 000 közötti egész szám legyen.',
            'dimensions_cm.*' => 'A rakomány méreteit centiméterben add meg, 1 és 65 535 közötti egész számokkal.',
        ])->validate();
    }

    private function candidates(Warehouse $warehouse, Product $product, array $cargo, ?int $locationId = null, ?string $search = null): array
    {
        $weight = isset($cargo['cargo_weight_kg']) ? WarehouseCapacity::grams($cargo['cargo_weight_kg']) : null;
        $slots = isset($cargo['pallet_slots']) ? (int) $cargo['pallet_slots'] : null;
        $dimensions = $cargo['dimensions_cm'] ?? [];
        $needle = Str::lower(Str::ascii(trim($search ?? '')));

        return DB::transaction(function () use ($warehouse, $product, $weight, $slots, $dimensions, $locationId, $needle) {
            $category = (int) $product->newQuery()->findOrFail($product->getKey(), ['category_id'])->category_id;
            $locations = $warehouse->storageLocations()->withCapacityUsage()->with(['system.zone', 'level', 'allowedCategories'])
                ->withExists(['placements', 'reservations', 'placements as stores_product' => fn ($query) => $query
                    ->whereHas('stock', fn ($stock) => $stock->where('product_id', $product->getKey()))])
                ->orderBy('code')->get();
            $committed = fn ($bins) => $bins->sum(fn ($bin) => (int) $bin->occupied_weight + (int) $bin->reserved_weight);
            $bySystem = $locations->whereNotNull('system_id')->groupBy('system_id')->map($committed);
            $byLevel = $locations->whereNotNull('level_id')->groupBy('level_id')->map($committed);
            $candidates = [];
            foreach ($locations as $location) {
                if ($locationId !== null && (int) $location->id !== $locationId) continue;
                if ($needle !== '' && !$this->matchesLocation($location, $needle)) continue;

                $limits = ['location_grams' => $location->freeWeight(), 'slots' => $location->freeSlots()];
                if ($location->system !== null) {
                    $limits['system_grams'] = $location->system->max_weight_grams - $bySystem[$location->system_id];
                    $limits['system_name'] = $location->system->name;
                }
                if ($location->level !== null) {
                    $limits['level_grams'] = $location->level->max_weight_grams - $byLevel[$location->level_id];
                }
                $candidates[] = $this->candidate($location, $category, $weight, $slots, $dimensions, $limits);
            }

            return $candidates;
        });
    }

    private function matchesLocation(WarehouseLocation $location, string $needle): bool
    {
        $position = array_filter($location->pickingPosition());
        $label = Str::lower(Str::ascii(implode(' ', [$location->code, $location->name, ...array_values($position)])));
        foreach (preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (!str_contains($label, $term)) return false;
        }

        return true;
    }

    private function candidate(WarehouseLocation $location, int $category, ?int $weight, ?int $slots, array $dimensions, array $limits): array
    {
        $categories = array_map('intval', $location->allowedCategories->modelKeys());
        $checks = $this->capacity->fitChecks($location, $category, $weight, $slots, $categories, $limits);
        $binDimensions = $this->capacity->locationDimensions($location);
        $checks += $this->capacity->dimensionChecks($dimensions, $binDimensions,
            $location->placements_exists || $location->reservations_exists);
        $failed = array_filter($checks, fn ($check) => $check['passed'] === false);
        $missing = array_filter($checks, fn ($check) => $check['passed'] === null);
        $passed = array_filter($checks, fn ($check) => $check['passed'] === true);
        $free = min($limits['location_grams'], $limits['level_grams'] ?? PHP_INT_MAX, $limits['system_grams'] ?? PHP_INT_MAX);
        $result = [
            'location_id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'kind' => $location->kind,
            'status' => $failed ? 'rejected' : ($missing ? 'conditional' : 'verified'),
            'checks' => ['passed' => ['warehouse', ...array_keys($passed)],
                'missing' => array_map(fn ($check) => $check['message'], $missing),
                'failed' => array_map(fn ($check) => $check['message'], $failed)],
            'free_weight_grams' => max(0, $free), 'free_pallet_slots' => $limits['slots'],
        ];
        if ($failed) return $result;

        $score = [
            'verified' => $missing === [],
            'same_product' => (bool) $location->stores_product,
            'remaining_pallet_slots' => $slots !== null && $limits['slots'] !== null ? $limits['slots'] - $slots : null,
            'remaining_weight_grams' => $weight === null ? null : $free - $weight,
            'volume_cm3' => count($binDimensions) === 3 ? array_product($binDimensions) : null,
            'nominal_weight_grams' => $location->max_weight_grams,
        ];

        return $result + ['score' => $score, 'reasons' => $this->rankingReasons($score)];
    }

    private function rankingReasons(array $score): array
    {
        $reasons = [];
        if ($score['same_product']) $reasons[] = 'Ezen a tárhelyen már van azonos termék.';
        if ($score['remaining_pallet_slots'] !== null) $reasons[] = 'A rakomány után '.$score['remaining_pallet_slots'].' szabad raklaphely maradna.';
        if ($score['remaining_weight_grams'] !== null) {
            $reasons[] = 'A rakomány után '.WarehouseLocation::formatWeight($score['remaining_weight_grams']).' kg szabad teherbírás maradna.';
        }
        $reasons[] = $score['volume_cm3'] === null
            ? 'A tárhely mérete hiányos, a névleges teherbírás alapján is rangsoroltuk.'
            : 'Azonos kapacitásmaradéknál a kisebb fizikai térfogat előnyt élvez.';

        return $reasons;
    }
}
