<?php

namespace App\Services;

use App\Models\{Product, Warehouse, WarehouseLocation};
use App\Support\WarehouseLoadDiscrepancies;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\{Collection, Str};

final class SmartPutaway
{

    public function __construct(private WarehouseCapacity $capacity, private WarehouseStockAvailability $availability) {}

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
     * of conditional candidates. Each includes its score, explanations and physical
     * stock snapshots (before/projected after) for the location and applicable hierarchy.
     * A null quantity means the corresponding figure could not be confirmed.
     * When there is a runner-up, ranking_explanation describes the decisive comparison.
     * Its compact summary is null if the comparison offers no useful standalone message.
     * Rejected locations are reported separately, within the requested warehouse only.
     *
     * @param array{quantity: int, cargo_weight_kg?: numeric|null, pallet_slots?: int|null, dimensions_cm?: array{width?: int|null, depth?: int|null, height?: int|null}|null} $cargo
     * @return array{warehouse_id: int, product_id: int, quantity: int, recommendations: list<array>, rejected: list<array>}
     */
    public function recommend(Warehouse $warehouse, Product $product, array $cargo, ?array $sourceRelease = null): array
    {
        $data = $this->validatedCargo($cargo);
        $candidates = $this->candidates($warehouse, $product, $data, null, null, $sourceRelease);
        $recommendations = array_values(array_filter($candidates, fn (array $candidate) => $candidate['status'] !== 'rejected'));
        $rejected = array_values(array_filter($candidates, fn (array $candidate) => $candidate['status'] === 'rejected'));
        usort($recommendations, fn (array $a, array $b) => PutawayRanking::compare($a['score'], $b['score'], $a['code'], $b['code']));
        if (isset($recommendations[1])) {
            $recommendations[0] += $this->firstRankExplanation($recommendations[0]['score'], $recommendations[1]['score']);
        }

        return ['warehouse_id' => (int) $warehouse->getKey(), 'product_id' => (int) $product->getKey(), 'quantity' => (int) $data['quantity'],
            'recommendations' => array_slice($recommendations, 0, 5), 'rejected' => $rejected];
    }

    /**
     * Read the matching candidates, including rejected ones, in code order.
     * Location filters do not exclude other bins from shared capacity totals.
     *
     * @return list<array>
     */
    public function assessLocations(
        Warehouse $warehouse, Product $product, array $cargo, ?int $locationId = null, ?string $search = null, ?array $sourceRelease = null,
    ): array {
        return $this->candidates($warehouse, $product, $this->validatedCargo($cargo), $locationId, $search, $sourceRelease);
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

    /** The optional source relief comes from the server's fully removed placements, never from client input. */
    private function candidates(
        Warehouse $warehouse, Product $product, array $cargo, ?int $locationId, ?string $search, ?array $sourceRelease,
    ): array {
        $weight = isset($cargo['cargo_weight_kg']) ? WarehouseCapacity::grams($cargo['cargo_weight_kg']) : null;
        $slots = isset($cargo['pallet_slots']) ? (int) $cargo['pallet_slots'] : null;
        $dimensions = $cargo['dimensions_cm'] ?? [];
        $needle = Str::lower(Str::ascii(trim($search ?? '')));

        return DB::transaction(function () use ($warehouse, $product, $cargo, $weight, $slots, $dimensions, $locationId, $needle, $sourceRelease) {
            $category = (int) $product->newQuery()->findOrFail($product->getKey(), ['category_id'])->category_id;
            $locations = $warehouse->storageLocations()->withCapacityUsage()->with(['system.zone', 'level', 'allowedCategories'])
                ->withExists(['placements', 'reservations', 'placements as stores_product' => fn ($query) => $query
                    ->whereHas('stock', fn ($stock) => $stock->where('product_id', $product->getKey()))])
                ->orderBy('code')->get();
            $discrepancies = WarehouseLoadDiscrepancies::summarize($locations, changedPlacements: $sourceRelease['changed_placements'] ?? []);
            $committed = fn ($bins) => $bins->sum(fn ($bin) => $bin->effectiveOccupiedWeight() + (int) $bin->reserved_weight);
            $bySystem = $locations->whereNotNull('system_id')->groupBy('system_id')->map($committed);
            $byLevel = $locations->whereNotNull('level_id')->groupBy('level_id')->map($committed);
            $source = $sourceRelease === null ? null : $locations->find($sourceRelease['location_id']);
            $released = $source ? max(0, min($sourceRelease['weight_grams'], (int) $source->occupied_weight)) : 0;
            if ($source?->system_id !== null) $bySystem[$source->system_id] -= $released;
            if ($source?->level_id !== null) $byLevel[$source->level_id] -= $released;

            $snapshot = $this->availability->snapshot((int) $warehouse->getKey(), (int) $product->getKey());
            $quantities = $this->productQuantities($locations, $snapshot);
            $quantity = (int) $cargo['quantity'];
            $canProject = $sourceRelease === null
                || ($source?->active && ($quantities['bin'][$source->id] ?? 0) >= $quantity);

            $candidates = [];
            foreach ($locations as $location) {
                if ($source?->id === $location->id) continue;
                if ($locationId !== null && (int) $location->id !== $locationId) continue;
                if ($needle !== '' && !$this->matchesLocation($location, $needle)) continue;

                $limits = ['location_grams' => $location->remainingWeight(), 'slots' => $location->remainingSlots(),
                    'discrepancies' => WarehouseLoadDiscrepancies::blockers($location, $discrepancies),
                    'discrepancy_severity' => WarehouseLoadDiscrepancies::severity($location, $discrepancies)];
                if ($location->system !== null) {
                    $limits['system_grams'] = $location->system->max_weight_grams - $bySystem[$location->system_id];
                }
                if ($location->level !== null) {
                    $limits['level_grams'] = $location->level->max_weight_grams - $byLevel[$location->level_id];
                }
                $candidate = $this->candidate($location, $category, $weight, $slots, $dimensions, $limits);
                if ($candidate['status'] !== 'rejected') {
                    $candidate['product_stock'] = $this->productStock($location, $source, $quantities, $canProject ? $quantity : null);
                }
                $candidates[] = $candidate;
            }

            return $candidates;
        });
    }

    /** Keep every bin in the totals, including inactive bins that still hold stock. */
    private function productQuantities(Collection $locations, array $snapshot): array
    {
        if (!$snapshot['consistent']) return ['bin' => null, 'level' => null, 'system' => null];

        $bins = array_column($snapshot['locations'], 'stored_quantity', 'location_id');
        $sum = fn (Collection $group) => $group->sum(fn (WarehouseLocation $bin) => $bins[$bin->id] ?? 0);
        $hasInvalidSystem = $locations->contains(fn (WarehouseLocation $bin) => $bin->system_id !== null
            && (int) $bin->system?->zone?->warehouse_id !== (int) $bin->warehouse_id);
        $hasInvalidLevel = $locations->contains(fn (WarehouseLocation $bin) => $bin->level_id !== null
            && ($bin->system_id === null || (int) $bin->level?->system_id !== (int) $bin->system_id));

        return [
            'bin' => $bins,
            'level' => $hasInvalidSystem || $hasInvalidLevel ? null : $locations->whereNotNull('level_id')->groupBy('level_id')->map($sum)->all(),
            'system' => $hasInvalidSystem ? null : $locations->whereNotNull('system_id')->groupBy('system_id')->map($sum)->all(),
        ];
    }

    private function productStock(WarehouseLocation $location, ?WarehouseLocation $source, array $totals, ?int $quantity): array
    {
        $balance = function (string $scope, int $id, bool $sameGroup = false) use ($totals, $quantity): array {
            $before = $totals[$scope] === null ? null : ($totals[$scope][$id] ?? 0);

            return [
                'before' => $before,
                'after' => $before === null || $quantity === null ? null : $before + ($sameGroup ? 0 : $quantity),
            ];
        };

        return [
            'bin' => $balance('bin', $location->id),
            'level' => $location->level_id === null ? null : $balance('level', $location->level_id, $source?->level_id === $location->level_id),
            'system' => $location->system_id === null ? null : $balance('system', $location->system_id, $source?->system_id === $location->system_id)
                + ['kind' => $location->system?->kind],
        ];
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
        // If a reading is historical, the conservative load remains binding, without asserting a measured excess.
        if ($limits['discrepancy_severity'] === 'warning') {
            foreach (['location_weight', 'level_weight', 'system_weight', 'pallet_space'] as $key) {
                if (isset($failed[$key])) {
                    $failed[$key]['message'] = 'A számított kapacitás nem elegendő. A jelenlegi terheléshez új mérés szükséges.';
                }
            }
        }
        $missing = array_filter($checks, fn ($check) => $check['passed'] === null);
        $passed = array_filter($checks, fn ($check) => $check['passed'] === true);
        $free = min($limits['location_grams'], $limits['level_grams'] ?? PHP_INT_MAX, $limits['system_grams'] ?? PHP_INT_MAX);
        $result = [
            'location_id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'kind' => $location->kind,
            'status' => $failed ? 'rejected' : ($missing ? 'conditional' : 'verified'),
            'checks' => ['passed' => ['warehouse', ...array_keys($passed)],
                'missing' => array_map(fn ($check) => $check['message'], $missing),
                'failed' => array_map(fn ($check) => $check['message'], $failed)],
            'discrepancy_severity' => $limits['discrepancy_severity'],
            'free_weight_grams' => $limits['discrepancies'] ? 0 : max(0, $free),
            'free_pallet_slots' => $limits['discrepancies'] && $limits['slots'] !== null ? 0 : $limits['slots'],
            'location_dimensions_cm' => $binDimensions ?: null,
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

        $reasons = $this->rankingReasons($score);

        return $result + ['score' => $score, 'reasons' => array_values($reasons),
            'ranking_notes' => array_values(array_diff_key($reasons, array_flip(['weight_capacity', 'pallet_capacity'])))];
    }

    private function firstRankExplanation(array $winner, array $runnerUp): array
    {
        $criterion = PutawayRanking::firstDifference($winner, $runnerUp);
        [$legacy, $summary, $detail] = match ($criterion) {
            'verified' => [
                'A megadott adatokkal ellenőrizhető az elférés.',
                null,
                'Az ellenőrzött alkalmasságú rekeszek előrébb kerülnek a feltételes ajánlásoknál.',
            ],
            'same_product' => [
                'Ezen a tárhelyen már van azonos termék.',
                'Itt már tárolunk ebből a termékből.',
                'Azonos alkalmassági állapot mellett előrébb kerül az a rekesz, ahol a termék már megtalálható.',
            ],
            'remaining_pallet_slots' => $runnerUp['remaining_pallet_slots'] === null ? [
                'Az elhelyezés utáni szabad raklaphely számítható.',
                null,
                'Itt kiszámítható, hány raklaphely marad szabadon az elhelyezés után. A következő ajánlásnál ez az adat hiányzik.',
            ] : [
                'Kevesebb raklaphely marad kihasználatlanul.',
                'Az áru elhelyezése után itt kevesebb raklaphely marad üresen.',
                'A rangsorban az elhelyezés után fennmaradó raklaphelyek száma döntött.',
            ],
            'remaining_weight_grams' => $runnerUp['remaining_weight_grams'] === null ? [
                'Az elhelyezés utáni szabad teherbírás számítható.',
                null,
                'Itt kiszámítható az elhelyezés után fennmaradó teherbírás. A következő ajánlásnál ez az adat hiányzik.',
            ] : [
                'Kevesebb szabad teherbírás marad kihasználatlanul.',
                'Az áru elhelyezése után itt kevesebb teherbírás marad kihasználatlanul.',
                'A rangsorban az elhelyezés után fennmaradó teherbírás döntött.',
            ],
            'volume_cm3' => $runnerUp['volume_cm3'] === null ? [
                'A rekesz térfogata a méretekből megállapítható.',
                null,
                'Ennek a rekesznek ismert a térfogata. A következő ajánlásnál hiányzik legalább egy méretadat.',
            ] : [
                $winner['verified'] ? 'Kisebb térfogatú rekesz is megfelel.' : 'A kisebb rekesztérfogat döntött.',
                $winner['verified'] ? 'A rakomány kisebb rekeszben is elfér.' : 'Ez a rekesz kisebb térfogatú.',
                'A kapacitási szempontok egyeztek, ezért a kisebb rekesztérfogat döntött.',
            ],
            'nominal_weight_grams' => [
                'A rekesz névleges teherbírása kisebb.',
                null,
                'A korábbi rangsorolási szempontok egyeztek, ezért a kisebb névleges teherbírás döntött.',
            ],
            'code' => [
                'Azonos rangsorolási értékek mellett a rekeszkód döntött.',
                null,
                'A rangsorolási szempontok egyeztek, ezért a rekeszkód szerinti sorrend döntött.',
            ],
        };

        return [
            'first_rank_reason' => $legacy,
            'ranking_explanation' => ['criterion' => $criterion, 'summary' => $summary, 'detail' => $detail],
        ];
    }

    private function rankingReasons(array $score): array
    {
        $reasons = [];
        if ($score['same_product']) $reasons['same_product'] = 'Ezen a tárhelyen már van azonos termék.';
        if ($score['remaining_pallet_slots'] !== null) $reasons['pallet_capacity'] = 'A rakomány után '.$score['remaining_pallet_slots'].' szabad raklaphely maradna.';
        if ($score['remaining_weight_grams'] !== null) {
            $reasons['weight_capacity'] = 'A rakomány után '.WarehouseLocation::formatWeight($score['remaining_weight_grams']).' kg szabad teherbírás maradna.';
        }
        $reasons['size_preference'] = $score['volume_cm3'] === null
            ? 'A tárhely mérete hiányos, a névleges teherbírás alapján is rangsoroltuk.'
            : 'Azonos kapacitásmaradéknál a kisebb fizikai térfogat előnyt élvez.';

        return $reasons;
    }
}
