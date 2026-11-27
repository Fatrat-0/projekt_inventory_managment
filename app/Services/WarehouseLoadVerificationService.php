<?php

namespace App\Services;

use App\Models\{Product, User, Warehouse, WarehouseLoadVerification, WarehouseLocation, WarehousePlacement};
use App\Policies\{WarehouseLoadVerificationPolicy, WarehouseRelocationPolicy};
use App\Support\WarehouseLoadDiscrepancies;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseLoadVerificationService
{
    public function __construct(
        private WarehouseLoadVerificationPolicy $policy,
        private WarehouseCapacity $capacity,
        private WarehouseStockAvailability $availability,
        private TransferWorkflowOperations $operations,
        private WarehouseRelocationPolicy $relocationPolicy,
    ) {}

    public function pending(User $actor, Warehouse $warehouse, array $filters): array
    {
        return $this->pendingPage($actor, $warehouse, $filters)->toArray()
            + ['can_confirm' => $this->policy->canVerify($actor, (int) $warehouse->getKey())];
    }

    public function pendingPage(User $actor, Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->read($actor, $warehouseId);

        return DB::transaction(function () use ($warehouse, $warehouseId, $filters) {
            $page = $this->pendingRows($warehouseId, $filters)
                ->when($filters['prioritize_discrepancies'] ?? false,
                    fn ($query) => $query->orderBy('priority'))
                ->orderBy('id')->paginate($filters['per_page'] ?? 25);
            $ids = $page->getCollection()->pluck('id')->all();
            $placements = $this->placements($warehouseId)->whereIntegerInRaw('id', $ids)->get()->keyBy('id');
            $events = $this->discrepancies($warehouseId)->whereIntegerInRaw('placement_id', $ids)
                ->with('location.system.zone', 'location.level')->get()->keyBy('placement_id');

            $limits = $this->documentedLimits($events);

            return $page->through(fn ($row) => $this->state($warehouse, $placements->get($row->id), $events->get($row->id), $limits));
        });
    }

    public function counts(User $actor, Warehouse $warehouse, ?int $locationId = null): array
    {
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->read($actor, $warehouseId);

        return $this->pendingRows($warehouseId, array_filter(['location_id' => $locationId]))
            ->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')->map(fn ($count) => (int) $count)
            ->all() + ['pending' => 0, 'discrepancy_open' => 0];
    }

    private function pendingRows(int $warehouseId, array $filters): QueryBuilder
    {
        $allDiscrepancies = $this->discrepancies($warehouseId);
        $pending = $this->placements($warehouseId)->withoutEagerLoads()
            ->where('load_requires_verification', true)->where('quantity', '>', 0)
            ->whereNotIn('id', (clone $allDiscrepancies)->select('placement_id'))
            ->when(isset($filters['location_id']), fn ($query) => $query->where('warehouse_location_id', $filters['location_id']))
            ->when(isset($filters['product_id']), fn ($query) => $query->whereHas('stock',
                fn ($stock) => $stock->where('product_id', $filters['product_id'])));
        $discrepancies = $allDiscrepancies->with('location')
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when(isset($filters['product_id']), fn ($query) => $query->where('product_id', $filters['product_id']));
        if ($keywords = $this->searchPatterns($filters)) {
            foreach ($keywords as $pattern) {
                $pending->where(fn ($query) => $query
                    ->whereHas('location', fn ($bin) => $this->match($bin, ['code'], $pattern))
                    ->orWhereHas('stock.product', fn ($product) => $this->match($product, ['product_name', 'sku'], $pattern)));
                $discrepancies->where(fn ($query) => $this->match($query, ['product_name', 'sku', 'location_code'], $pattern)
                    ->orWhereHas('location', fn ($bin) => $this->match($bin, ['code'], $pattern))
                    ->orWhereHas('product', fn ($product) => $this->match($product, ['product_name', 'sku'], $pattern)));
            }
        }
        $pending->selectRaw("id, 'pending' AS status");
        $discrepancies->selectRaw("placement_id AS id, 'discrepancy_open' AS status");
        $discrepancies->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM warehouse_placements AS current_load
            WHERE current_load.id = warehouse_load_verifications.placement_id
                AND current_load.warehouse_location_id = warehouse_load_verifications.location_id
                AND current_load.quantity = warehouse_load_verifications.quantity
                AND current_load.load_requires_verification
                AND current_load.cargo_weight_grams = warehouse_load_verifications.previous_weight_grams
                AND current_load.pallet_slots = warehouse_load_verifications.previous_pallet_slots) THEN 0 ELSE 1 END AS priority');
        $pending->selectRaw('2 AS priority');
        $rows = match ($filters['state'] ?? 'active') {
            'pending' => $pending->toBase(),
            'discrepancy_open' => $discrepancies->toBase(),
            default => $pending->unionAll($discrepancies)->toBase(),
        };

        return DB::query()->fromSub($rows, 'pending_loads');
    }

    private function searchPatterns(array $filters): array
    {
        return array_map(fn (string $term) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%',
            preg_split('/\s+/u', trim($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function match(Builder $query, array $columns, string $pattern): Builder
    {
        return $query->where(function ($group) use ($columns, $pattern) {
            foreach ($columns as $column) $group->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
        });
    }

    public function show(User $actor, Warehouse $warehouse, int $placementId): array
    {
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->read($actor, $warehouseId);

        return DB::transaction(function () use ($actor, $warehouse, $warehouseId, $placementId) {
            [$placement, $event] = $this->readPlacement($warehouseId, $placementId);

            $canRelocate = false;
            if ($event && $placement?->location->active) {
                $stock = $this->availability->snapshot($warehouseId, $placement->stock->product_id);
                $canRelocate = $stock['consistent'] && $stock['pickable_quantity'] > 0
                    && $this->relocationPolicy->canAccessWarehouse($actor, $warehouseId);
            }

            return ['placement' => $this->state($warehouse, $placement, $event, $this->documentedLimits(collect([$event])->filter())),
                'can_relocate' => $canRelocate,
                'can_confirm' => $this->policy->canVerify($actor, $warehouseId) && ($event !== null || $placement->load_requires_verification),
                'capacity' => $this->capacity->loadSafety($placement?->location ?? $event->location)];
        });
    }

    public function preview(User $actor, Warehouse $warehouse, int $placementId, array $input): array
    {
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->read($actor, $warehouseId);
        $data = $this->validate($input, false);

        return DB::transaction(function () use ($actor, $warehouse, $warehouseId, $placementId, $data) {
            [$placement, $event] = $this->readPlacement($warehouseId, $placementId);
            $this->assertPending($placement, $event, $data['revision']);
            $this->assertMeasurementForState($placement, $data);
            if ($placement) {
                $this->assertStock($this->availability->snapshot($warehouseId, $placement->stock->product_id), $placement->stock_id);
            }
            $location = $placement?->location ?? $event->location;

            return ['placement' => $this->state($warehouse, $placement, $event, $this->documentedLimits(collect([$event])->filter())),
                'can_confirm' => $this->policy->canVerify($actor, $warehouseId),
                'measurement' => [
                    'weight_grams' => $data['weight_grams'], 'pallet_slots' => $data['pallet_slots'], 'note' => $data['note'],
                    'weight_difference_grams' => $placement ? $data['weight_grams'] - $placement->cargo_weight_grams : null,
                    'pallet_difference' => $placement ? $data['pallet_slots'] - $placement->pallet_slots : null,
                ],
            ] + $this->assess($location, $placement, $placement?->stock->product, $data, $event);
        });
    }

    public function confirm(User $actor, Warehouse $warehouse, int $placementId, array $input): WarehouseLoadVerification
    {
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->verify($actor, $warehouseId);
        $data = $this->validate($input, true);
        $key = $data['operation_key'];
        $hash = $this->operations->fingerprint('warehouse_load_verification', $actor, $placementId,
            ['warehouse_id' => $warehouseId] + array_diff_key($data, ['operation_key' => true]));

        try {
            return DB::transaction(function () use ($actor, $warehouseId, $placementId, $data, $key, $hash) {
                $currentActor = $this->operations->currentActor($actor);
                $this->policy->verify($currentActor, $warehouseId, locked: true);
                if ($prior = WarehouseLoadVerification::where('operation_key', $key)->first()) {
                    return $this->replay($prior, $hash);
                }

                // Discover the product, then take the shared inventory lock order before reading the current state again.
                $identity = $this->placements($warehouseId)->withoutEagerLoads()->with('stock')->find($placementId);
                $initialEvent = $identity ? null : $this->discrepancies($warehouseId)->where('placement_id', $placementId)->first();
                $productId = $identity?->stock->product_id ?? $initialEvent?->product_id;
                if ($productId === null) $this->stale();
                $product = Product::whereKey($productId)->lockForUpdate()->first();
                if (!$product) $this->stale();
                $warehouse = Warehouse::whereKey($warehouseId)->lockForUpdate()->firstOrFail();
                $location = $warehouse->storageLocations()->whereKey($identity?->warehouse_location_id ?? $initialEvent->location_id)
                    ->lockForUpdate()->first();
                if (!$location) $this->stale();

                $stock = $this->availability->snapshotForUpdate($warehouseId, (int) $product->getKey());
                $placement = $location->placements()->where('stock_id', $stock['stock_id'])->whereKey($placementId)->lockForUpdate()->first();
                $open = $this->discrepancies($warehouseId, locked: true)->where('placement_id', $placementId)->sharedLock()->first();
                $this->assertPending($placement, $open, $data['revision']);
                $this->assertMeasurementForState($placement, $data);
                if ($placement) $this->assertStock($stock, $identity?->stock_id ?? $placement->stock_id);

                $assessment = $this->assess($location, $placement, $product, $data, $open, locked: true);
                $mask = $this->violationMask($assessment['checks']);
                if ($mask !== 0 && empty($data['measurement_confirmed'])) $this->requirePhysicalConfirmation();
                $event = [
                    'warehouse_id' => $warehouseId, 'warehouse_name' => $warehouse->warehouse_name,
                    'location_id' => $location->id, 'location_code' => $location->code, 'location_name' => $location->name,
                    'placement_id' => $placementId, 'quantity' => $placement?->quantity ?? 0,
                    'product_id' => $product->getKey(), 'product_name' => $product->product_name, 'sku' => $product->sku,
                    'previous_weight_grams' => $placement?->cargo_weight_grams ?? 0, 'measured_weight_grams' => $data['weight_grams'],
                    'previous_pallet_slots' => $placement?->pallet_slots ?? 0, 'measured_pallet_slots' => $data['pallet_slots'],
                    'placement_revision' => $data['revision'], 'result' => $assessment['can_verify'] ? 'verified' : 'rejected',
                    'errors' => $assessment['errors'], 'actor_id' => $currentActor->id, 'actor_name' => $currentActor->name,
                    'measured_at' => now()->startOfSecond(), 'reason' => $data['reason'], 'note' => $data['note'],
                    'operation_key' => $key, 'request_hash' => $hash, 'capacity_violation_mask' => $mask,
                    'resolves_verification_id' => $assessment['can_verify'] ? $open?->id : null,
                ];

                if ($assessment['can_verify'] && $placement) {
                    $placement->update([
                        'cargo_weight_grams' => $data['weight_grams'], 'pallet_slots' => $data['pallet_slots'],
                        'load_requires_verification' => false,
                    ]);
                }

                return WarehouseLoadVerification::create($event)->refresh();
            }, 3);
        } catch (ConflictHttpException|UniqueConstraintViolationException $error) {
            // Current read after rollback also works when an outer transaction already established a snapshot.
            return DB::transaction(function () use ($actor, $warehouseId, $key, $hash, $error) {
                $this->policy->verify($this->operations->currentActor($actor), $warehouseId, locked: true);
                $prior = WarehouseLoadVerification::where('operation_key', $key)->sharedLock()->first();
                if (!$prior) throw $error;

                return $this->replay($prior, $hash);
            });
        }
    }

    public function history(User $actor, Warehouse $warehouse, array $filters): array
    {
        return $this->historyPage($actor, $warehouse, $filters)->toArray();
    }

    public function historyPage(User $actor, Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        $this->policy->read($actor, (int) $warehouse->getKey());
        $query = WarehouseLoadVerification::where('warehouse_id', $warehouse->getKey());
        foreach ($this->searchPatterns($filters) as $pattern) $this->match($query, ['product_name', 'sku', 'location_code'], $pattern);
        foreach (['placement_id', 'location_id', 'product_id'] as $field) {
            if (isset($filters[$field])) $query->where($field, $filters[$field]);
        }

        return DB::transaction(function () use ($query, $filters) {
            $closedBy = WarehouseLoadVerification::query()->from('warehouse_load_verifications as resolution')
                ->whereColumn('resolution.warehouse_id', 'warehouse_load_verifications.warehouse_id')
                ->whereColumn('resolution.placement_id', 'warehouse_load_verifications.placement_id')
                ->whereColumn('resolution.id', '>', 'warehouse_load_verifications.id')->where('resolution.result', 'verified')
                ->oldest('resolution.id')->select('resolution.id')->limit(1);
            $page = $query->addSelect('warehouse_load_verifications.*')->selectSub($closedBy, 'closed_by')
                ->latest('id')->paginate($filters['per_page'] ?? 25);
            $resolutions = WarehouseLoadVerification::whereIntegerInRaw('id', $page->getCollection()->pluck('closed_by')->filter()->unique()->all())
                ->get()->keyBy('id');

            return $page->through(function (WarehouseLoadVerification $event) use ($resolutions) {
                $resolution = $event->capacity_violations ? $resolutions->get($event->closed_by) : null;
                $data = $event->makeHidden('closed_by')->toArray();
                if ($event->capacity_violations) {
                    $data['discrepancy_open'] = $resolution === null;
                    $data['resolution'] = $resolution ? $resolution->only(['id', 'measured_at', 'actor_id', 'actor_name',
                        'quantity', 'measured_weight_grams', 'measured_pallet_slots']) + [
                        'method' => $resolution->quantity === 0 ? 'confirmed_empty' : 'remeasured',
                    ] : null;
                }

                return $data;
            });
        });
    }

    private function placements(int $warehouseId): Builder
    {
        return WarehousePlacement::with(['location.system.zone', 'location.level', 'stock.product'])
            ->whereHas('location', fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereHas('stock', fn ($query) => $query->where('warehouse_id', $warehouseId));
    }

    private function discrepancies(int $warehouseId, bool $locked = false): Builder
    {
        return WarehouseLoadVerification::where('warehouse_id', $warehouseId)->openDiscrepancies($locked);
    }

    private function readPlacement(int $warehouseId, int $placementId): array
    {
        $placement = $this->placements($warehouseId)->find($placementId);
        $event = $this->discrepancies($warehouseId)->where('placement_id', $placementId)
            ->with('location.system.zone', 'location.level')->first();
        abort_unless($placement || $event?->location, 404);

        return [$placement, $event];
    }

    private function state(Warehouse $warehouse, ?WarehousePlacement $placement, ?WarehouseLoadVerification $event, array $limits): array
    {
        $location = $placement?->location ?? $event->location;
        $product = $placement?->stock->product;
        $pending = $placement?->load_requires_verification ?? false;
        $warnings = $pending ? ['A terhelés a korábbi állapotból maradt meg. A teljes megmaradt mennyiséget mérd le.'] : [];
        if (!$location->active) $warnings[] = 'A rekesz ki van kapcsolva.';
        if ($event) $warnings[] = 'Nyitott terhelési eltérés van. A további terhelés korlátozott az eltérés rendezéséig.';
        $current = $event && WarehouseLoadDiscrepancies::isCurrent($event, $placement);

        return [
            'placement_id' => $placement?->id ?? $event->placement_id,
            'revision' => $this->revision($placement, $event), 'quantity' => $placement?->quantity ?? 0,
            'warehouse' => ['id' => $warehouse->getKey(), 'name' => $warehouse->warehouse_name],
            'location' => ['id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'kind' => $location->kind,
                'active' => $location->active, 'position' => $location->pickingPosition(), 'system_id' => $location->system_id,
                'on_plan' => ($location->system_id === null ? $location->floor_x : $location->system?->floor_x) !== null],
            'product' => ['id' => $product?->getKey() ?? $event->product_id,
                'name' => $product?->product_name ?? $event->product_name, 'sku' => $product?->sku ?? $event->sku],
            'recorded_weight_grams' => $placement?->cargo_weight_grams, 'recorded_pallet_slots' => $placement?->pallet_slots,
            'load_requires_verification' => $pending, 'verification_reason' => $pending ? 'retained_load' : null,
            'status' => $event ? 'discrepancy_open' : ($pending ? 'pending' : 'verified'),
            'placement_present' => $placement !== null,
            'severity' => $current ? 'critical' : ($event || $pending ? 'warning' : 'success'),
            'warnings' => $warnings, 'last_rejection' => $event?->attributesToArray(),
            'discrepancy' => $event === null ? null : [
                'open' => true, 'measurement' => $event->attributesToArray(), 'measurement_current' => $current,
                'violations' => $event->capacity_violations,
                'limits' => $current ? ($limits[$event->id] ?? []) : [],
                'next_action' => !$placement ? 'confirm_empty' : 'remeasure',
                'next_action_label' => !$placement ? 'A helyszínen ellenőrizd, hogy a tétel teljesen kikerült a rekeszből.'
                    : 'Rendezd a kapacitáseltérést, majd mérd le az aktuális teljes tételt.',
            ],
        ];
    }

    /**
     * Read the currently configured limits in one batch for the visible open events.
     * Quantify current excess only when each placement has a known load in that scope.
     */
    private function documentedLimits(Collection $events): array
    {
        if ($events->isEmpty()) return [];

        $locations = $events->pluck('location');
        $systems = $locations->pluck('system_id')->filter()->unique()->values();
        $bins = WarehouseLocation::query()->withCapacityUsage()
            ->withCount(['placements as pending_load_count' => fn ($rows) => $rows->where('load_requires_verification', true)])
            ->where(fn ($query) => $query->whereIntegerInRaw('id', $locations->pluck('id')->all())
                ->orWhereIntegerInRaw('system_id', $systems->all()))->get();
        $uncertain = $bins->filter(fn ($bin) => (int) $bin->pending_load_count !== $bin->openLoadDiscrepancies->count()
            || $bin->openLoadDiscrepancies->contains(fn ($reading) => !WarehouseLoadDiscrepancies::isCurrent($reading, $reading->placement)))
            ->keyBy('id');
        $byLocation = $bins->groupBy('id');
        $byLevel = $bins->whereNotNull('level_id')->groupBy('level_id');
        $bySystem = $bins->whereNotNull('system_id')->groupBy('system_id');
        $results = [];
        foreach ($events as $event) {
            $bin = $event->location;
            $scopes = [
                'location_weight' => [$bin->max_weight_grams, $byLocation[$bin->id]],
                'level_weight' => [$bin->level?->max_weight_grams, $byLevel->get($bin->level_id)],
                'system_weight' => [$bin->system?->max_weight_grams, $bySystem->get($bin->system_id)],
                'pallet_space' => [$bin->max_pallet_slots, $byLocation[$bin->id]],
            ];
            foreach ($event->capacity_violations as $key) {
                [$limit, $group] = $scopes[$key] ?? [null, null];
                if ($limit === null || !$group || $group->contains(fn ($b) => $uncertain->has($b->id))) {
                    continue;
                }

                $slots = $key === 'pallet_space';
                $projected = $group->sum(fn ($b) => $slots
                    ? (int) $b->occupied_slots + (int) $b->reserved_slots + $b->openLoadDiscrepancies
                        ->sum(fn ($reading) => $reading->measured_pallet_slots - $reading->previous_pallet_slots)
                    : (int) $b->occupied_weight + (int) $b->reserved_weight + $b->openLoadDiscrepancies
                        ->sum(fn ($reading) => $reading->measured_weight_grams - $reading->previous_weight_grams));
                if ($projected <= $limit) continue;

                $results[$event->id][$key] = ['limit' => $limit, 'projected' => $projected, 'excess' => $projected - $limit,
                    'unit' => $slots ? 'pallet_slots' : 'grams'];
            }
        }

        return $results;
    }

    private function assess(
        WarehouseLocation $location, ?WarehousePlacement $placement, ?Product $product, array $data,
        ?WarehouseLoadVerification $event, bool $locked = false,
    ): array {
        if (!$placement) {
            return ['can_verify' => true, 'severity' => 'success', 'errors' => [], 'checks' => [], 'remaining_capacity' => [],
                'capacity_comparison' => [], 'requires_physical_confirmation' => true];
        }
        $assessment = $this->capacity->verifyPlacementLoad($location, $placement, $product, $data['weight_grams'], $data['pallet_slots'], $locked);
        $errors = [];
        $checks = $assessment['checks'];
        foreach ($checks as &$check) {
            if ($check['field'] === 'to_location_id') $check['field'] = 'location_id';
            if ($check['passed'] === true) {
                $check['message'] = null;
                continue;
            }
            $errors[$check['field']][] = $check['message'];
        }
        unset($check);
        $critical = $this->violationMask($checks) !== 0;
        $otherBlock = $assessment['other_discrepancies'] !== [];
        $remaining = $comparison = [];
        $capacityChecks = ['location_weight_grams' => 'location_weight', 'level_weight_grams' => 'level_weight',
            'system_weight_grams' => 'system_weight', 'pallet_slots' => 'pallet_space'];
        foreach ($assessment['available'] as $scope => $available) {
            if ($available === null) continue;
            $pallets = $scope === 'pallet_slots';
            $recorded = $pallets ? $placement->pallet_slots : $placement->cargo_weight_grams;
            $observed = $pallets ? $event?->measured_pallet_slots : $event?->measured_weight_grams;
            $before = $available - max($recorded, $observed ?? 0);
            $measured = $pallets ? $data['pallet_slots'] : $data['weight_grams'];
            $after = $available - $measured;

            $remaining[$scope] = [
                'before' => $event || $otherBlock ? min(0, $before) : $before,
                'after' => ($event && $errors) || $otherBlock ? min(0, $after) : $after,
            ];

            $limit = $checks[$capacityChecks[$scope]]['capacity']['limit'];
            // An audited discrepancy preserves the recorded load and accounts for the new observation.
            $effectiveAfter = $critical ? $available - max($recorded, $measured) : ($errors ? $before : $after);
            $comparison[$scope] = [
                'limit' => $limit, 'unit' => $pallets ? 'pallet_slots' : 'grams',
                'before' => $this->capacitySnapshot($limit, $before, $location->active, $event !== null || $otherBlock),
                'after' => $this->capacitySnapshot($limit, $effectiveAfter, $location->active,
                    $critical || ($event !== null && $errors !== []) || $otherBlock),
            ];
        }

        return ['can_verify' => $errors === [], 'severity' => $critical ? 'critical' : ($errors ? 'warning' : 'success'),
            'errors' => $errors, 'checks' => $checks, 'remaining_capacity' => $remaining, 'capacity_comparison' => $comparison,
            'requires_physical_confirmation' => $critical,
            'other_discrepancies' => $assessment['other_discrepancies']];
    }

    private function capacitySnapshot(int $limit, int $remaining, bool $active, bool $held): array
    {
        return [
            'committed' => $limit - $remaining,
            'remaining' => $remaining,
            'excess' => max(0, -$remaining),
            'usable' => $active && !$held ? max(0, $remaining) : 0,
            'block' => $held ? 'load_discrepancy' : ($active ? null : 'inactive'),
        ];
    }

    private function revision(?WarehousePlacement $placement, ?WarehouseLoadVerification $event): string
    {
        $state = $placement?->only(['id', 'warehouse_location_id', 'stock_id', 'quantity',
            'cargo_weight_grams', 'pallet_slots', 'load_requires_verification']);
        if ($event) $state = [$state, $event->id];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function assertPending(?WarehousePlacement $placement, ?WarehouseLoadVerification $event, string $revision): void
    {
        if ((!$event && (!$placement || $placement->quantity <= 0 || !$placement->load_requires_verification))
            || !hash_equals($this->revision($placement, $event), $revision)) $this->stale();
    }

    private function assertMeasurementForState(?WarehousePlacement $placement, array $data): void
    {
        if ($placement && $data['weight_grams'] > 0) return;
        if (!$placement && $data['weight_grams'] === 0 && $data['pallet_slots'] === 0) {
            if (array_key_exists('operation_key', $data) && empty($data['measurement_confirmed'])) $this->requirePhysicalConfirmation();

            return;
        }

        throw ValidationException::withMessages(['cargo_weight_kg' => $placement
            ? 'A rekeszben maradt áru teljes bruttó tömegének pozitívnak kell lennie.'
            : 'A tétel már nincs a rekeszben. A helyszíni ellenőrzéshez adj meg 0 kg-ot és 0 raklaphelyet.']);
    }

    private function requirePhysicalConfirmation(): never
    {
        throw ValidationException::withMessages([
            'measurement_confirmed' => 'Erősítsd meg, hogy a terhelést vagy a tétel eltávolítását a helyszínen ellenőrizted.',
        ]);
    }

    private function violationMask(array $checks): int
    {
        $mask = 0;
        foreach (WarehouseLoadVerification::VIOLATIONS as $code => [$bit]) {
            if (($checks[$code]['passed'] ?? true) === false) $mask |= $bit;
        }

        return $mask;
    }

    private function assertStock(array $stock, int $stockId): void
    {
        if (!$stock['consistent'] || $stock['stock_id'] !== $stockId) {
            throw new ConflictHttpException('A rekesztétel és a raktári készlet adatai eltérnek. Frissítsd és ellenőrizd a készletet.');
        }
    }

    private function stale(): never
    {
        throw new ConflictHttpException('A rekesztétel időközben megváltozott vagy már ellenőrizték. Töltsd be a friss állapotot, és ellenőrizd újra a mérést.');
    }

    private function replay(WarehouseLoadVerification $event, string $hash): WarehouseLoadVerification
    {
        if (!hash_equals($event->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már más méréshez tartozik. Új méréshez használj új azonosítót.');
        }

        return $event;
    }

    private function validate(array $input, bool $confirm): array
    {
        $rules = [
            'revision' => 'required|string|size:64|regex:/^[a-f0-9]+$/D',
            'cargo_weight_kg' => 'required|numeric|decimal:0,3|min:0|max:1000000',
            'pallet_slots' => 'required|integer|min:0|max:1000000',
            'quantity' => 'missing', 'remaining_quantity' => 'missing',
            'reason' => 'nullable|string|max:255', 'note' => 'nullable|string|max:500',
        ];
        if ($confirm) $rules += ['operation_key' => 'required|uuid', 'measurement_confirmed' => 'sometimes|accepted'];
        $data = Validator::make($input, $rules, [
            'revision.*' => 'A rekesztétel aktuális revíziója szükséges.',
            'cargo_weight_kg.*' => 'Add meg a teljes mért bruttó tömeget 0 és 1 000 000 kg között, legfeljebb három tizedesjeggyel.',
            'pallet_slots.*' => 'Add meg a tényleges raklaphelyigényt egész számmal. Raklap nélküli áruhoz adj meg 0-t.',
            'quantity.missing' => 'A darabszámot a szerver a rekesz aktuális tétele alapján állapítja meg.',
            'remaining_quantity.missing' => 'A darabszám a terhelésellenőrzésben nem módosítható.',
            'measurement_confirmed.*' => 'Erősítsd meg a tényleges helyszíni mérést vagy a tétel eltávolítását.',
            'operation_key.*' => 'Érvényes UUID műveleti azonosító szükséges.',
            'reason.*' => 'A mérés indoka legfeljebb 255 karakter lehet.',
            'note.*' => 'A megjegyzés legfeljebb 500 karakter lehet.',
        ])->validate();

        $reason = trim($data['reason'] ?? '');
        $note = trim($data['note'] ?? '');

        return [
            'revision' => $data['revision'], 'weight_grams' => WarehouseCapacity::grams($data['cargo_weight_kg']),
            'pallet_slots' => (int) $data['pallet_slots'], 'reason' => $reason === '' ? null : $reason,
            'note' => $note === '' ? null : $note,
        ] + ($confirm ? ['operation_key' => strtolower($data['operation_key'])] : [])
            + (isset($data['measurement_confirmed']) ? ['measurement_confirmed' => true] : []);
    }
}
