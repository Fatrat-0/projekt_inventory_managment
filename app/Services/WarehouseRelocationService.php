<?php

namespace App\Services;

use App\Models\{Product, User, Warehouse, WarehouseLocation, WarehouseRelocation};
use App\Policies\WarehouseRelocationPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseRelocationService
{
    private const GEOMETRY_CHECKS = ['cargo_dimensions', 'location_dimensions', 'remaining_physical_space'];

    public function __construct(
        private WarehouseRelocationPolicy $policy,
        private WarehouseStockAvailability $availability,
        private WarehouseRelocationSources $sources,
        private WarehouseCapacity $capacity,
        private SmartPutaway $putaway,
        private TransferWorkflowOperations $operations,
    ) {}

    public function candidates(User $actor, array $input): array
    {
        $data = $this->validate($input, 'candidates');

        return DB::transaction(function () use ($actor, $data) {
            ['warehouse' => $warehouse, 'product' => $product, 'source' => $source, 'plan' => $plan] =
                $this->evaluateSource($actor, $data);
            $result = ['warehouse_id' => $data['warehouse_id'], 'product_id' => $data['product_id'],
                'source' => $source, 'cargo' => $this->cargoSummary($plan, $data), 'source_allocations' => $plan['allocations']];
            if ($data['mode'] === 'cargo') return $result;

            $release = ['location_id' => $source['location_id'], 'weight_grams' => $plan['released_weight_grams']];
            $cargo = $this->assessmentInput($data, $plan);
            $results = $data['mode'] === 'recommendations'
                ? $this->putaway->recommend($warehouse, $product, $cargo, $release)
                : ['locations' => $this->putaway->assessLocations($warehouse, $product, $cargo,
                    $data['to_location_id'] ?? null, $data['search'], $release)];
            if ($data['mode'] === 'search' && isset($data['to_location_id']) && !$results['locations']) $this->invalidTarget();

            return $result + $results;
        });
    }

    public function preview(User $actor, array $input): array
    {
        $data = $this->validate($input, 'preview');

        return DB::transaction(function () use ($actor, $data) {
            ['warehouse' => $warehouse, 'product' => $product, 'source' => $source, 'plan' => $plan,
                'target' => $target, 'stock' => $stock] = $this->evaluateSource($actor, $data);
            $location = $this->putaway->assessLocations($warehouse, $product, $this->assessmentInput($data, $plan),
                $data['to_location_id'], sourceRelease: ['location_id' => $source['location_id'],
                    'weight_grams' => $plan['released_weight_grams']])[0] ?? null;
            if ($location === null) $this->invalidTarget();

            $missing = $location['checks']['missing'];
            $geometry = array_intersect_key($missing, array_flip(self::GEOMETRY_CHECKS));
            $blockers = $location['checks']['failed'] + ($data['site_verified'] ? array_diff_key($missing, $geometry) : $missing);
            if ($plan['source_check_required'] && !$data['source_load_verified']) {
                $blockers['source_load_verified'] = $this->sourceVerificationMessage();
            }

            return [
                'warehouse_id' => $data['warehouse_id'], 'product_id' => $data['product_id'],
                'source' => $source, 'cargo' => $this->cargoSummary($plan, $data), 'location' => $location,
                'site_verification_required' => $geometry !== [], 'source_allocations' => $plan['allocations'],
                'target_revision' => $this->sources->targetRevision($target),
                'target_stored_quantity' => collect($stock['locations'])->firstWhere('location_id', $target->id)['stored_quantity'] ?? 0,
                'blocking_reasons' => (object) $blockers, 'can_relocate' => $blockers === [],
            ];
        });
    }

    public function relocate(User $actor, array $input): array
    {
        $data = $this->validate($input, 'write');
        $key = $data['operation_key'];
        $hash = $this->operations->fingerprint('warehouse_relocation', $actor, null, array_diff_key($data, ['operation_key' => true]));

        try {
            return DB::transaction(function () use ($actor, $data, $key, $hash) {
                $current = $this->operations->currentActor($actor);
                $this->policy->authorize($current, $data['warehouse_id'], locked: true);
                if ($prior = WarehouseRelocation::where('operation_key', $key)->first()) return $this->replay($prior, $hash);

                ['warehouse' => $warehouse, 'product' => $product, 'source' => $source, 'stock' => $stock,
                    'placements' => $placements, 'plan' => $plan, 'target' => $target] =
                    $this->evaluateSource($current, $data, locked: true);
                $this->assertCompleteCargo($plan, $data);
                if (isset($data['target_revision']) && !hash_equals($data['target_revision'], $this->sources->targetRevision($target, locked: true))) {
                    throw new ConflictHttpException('A célrekesz kapacitása közben megváltozott. Ellenőrizd újra az áthelyezést.');
                }

                // Fully removed rows release their actual recorded capacity before the common check.
                // A partial row retains all of its recorded load and is marked for verification.
                $placementsById = $placements->keyBy('id');
                foreach ($plan['allocations'] as $allocation) {
                    $placement = $placementsById->get($allocation['placement_id']);
                    if ($allocation['remaining_quantity'] === 0) $placement->delete();
                    else $placement->update(['quantity' => $allocation['remaining_quantity'], 'load_requires_verification' => true]);
                }
                $placed = $this->capacity->placeAssignedStock($target, $product, $stock['stock_id'], $data['quantity'],
                    $plan['weight_grams'], $plan['pallet_slots'], $data['dimensions_cm'], $data['site_verified']);
                $targetBefore = collect($stock['locations'])->firstWhere('location_id', $target->id)['stored_quantity'] ?? 0;

                return WarehouseRelocation::create([
                    'reference' => 'REK-'.strtoupper((string) Str::ulid()),
                    'warehouse_id' => $warehouse->getKey(), 'warehouse_name' => $warehouse->warehouse_name,
                    'product_id' => $product->getKey(), 'product_name' => $product->product_name, 'sku' => $product->sku,
                    'from_location_id' => $source['location_id'], 'from_location_code' => $source['code'], 'from_location_name' => $source['name'],
                    'to_location_id' => $target->id, 'to_location_code' => $target->code, 'to_location_name' => $target->name,
                    'destination_placement_id' => $placed['placement']->id, 'quantity' => $data['quantity'],
                    'cargo_weight_grams' => $plan['weight_grams'], 'pallet_slots' => $plan['pallet_slots'],
                    'dimensions_cm' => $data['dimensions_cm'] ?: null, 'load_source' => $plan['load_source'],
                    'source_load_verified' => $data['source_load_verified'], 'site_verified' => $data['site_verified'],
                    'geometry_status' => $placed['geometry_status'], 'source_allocations' => $plan['allocations'],
                    'balances' => [
                        'warehouse_physical_quantity' => $stock['physical_quantity'], 'warehouse_pickable_quantity' => $stock['pickable_quantity'],
                        'from_before' => $source['stored_quantity'], 'from_after' => $source['stored_quantity'] - $data['quantity'],
                        'to_before' => $targetBefore, 'to_after' => $targetBefore + $data['quantity'],
                    ],
                    'actor_id' => $current->id, 'actor_name' => $current->name, 'relocated_at' => now()->startOfSecond(),
                    'reason' => $data['reason'], 'note' => $data['note'], 'operation_key' => $key, 'request_hash' => $hash,
                ])->refresh()->toArray();
            }, 3);
        } catch (ValidationException|ConflictHttpException|UniqueConstraintViolationException $error) {
            // A competing commit may be invisible to an earlier InnoDB snapshot.
            // Locking lookup happens after rollback, with no subsequent insert on a missing key.
            $prior = WarehouseRelocation::where('operation_key', $key)->sharedLock()->first();
            if ($prior) return $this->replay($prior, $hash);
            throw $error;
        }
    }

    private function replay(WarehouseRelocation $prior, string $hash): array
    {
        if (!hash_equals($prior->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már eltérő áthelyezéshez tartozik. Az új művelethez használj új azonosítót.');
        }

        return $prior->toArray();
    }

    private function evaluateSource(User $actor, array $data, bool $locked = false): array
    {
        $warehouseId = $data['warehouse_id'];
        if (!$locked) $this->policy->authorize($actor, $warehouseId);
        // Matches stock mutations: product, warehouse, then location and stock/placement rows.
        $product = Product::whereKey($data['product_id'])->when($locked, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $warehouse = Warehouse::whereKey($warehouseId)->when($locked, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $ids = [$data['from_location_id']];
        if (isset($data['to_location_id'])) $ids[] = $data['to_location_id'];
        $bins = $warehouse->storageLocations()->whereKey($ids)->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $from = $bins->get($data['from_location_id']);
        if ($from === null || !$from->active) {
            throw ValidationException::withMessages(['from_location_id' => 'Válassz aktív forrásrekeszt a megadott raktárban.']);
        }
        $target = $bins->get($data['to_location_id'] ?? 0);
        if (isset($data['to_location_id']) && $target === null) $this->invalidTarget();
        $stock = $locked ? $this->availability->snapshotForUpdate($warehouseId, $product->getKey())
            : $this->availability->snapshot($warehouseId, $product->getKey());
        if (!$stock['consistent']) {
            throw new ConflictHttpException('A raktári és a tárhelyenkénti készlet eltér. Az áthelyezés előtt ellenőrizd a készletet.');
        }
        $placements = $this->sources->placements($stock, [$from->id], $locked);
        $source = $this->sources->describe($from, $stock, $placements);
        if (isset($data['source_revision']) && !hash_equals($source['source_revision'], $data['source_revision'])) {
            throw new ConflictHttpException('A forrásrekesz készlete vagy terhelése megváltozott. Frissítsd a forrás adatait.');
        }
        if ($data['quantity'] > $source['movable_quantity']) {
            throw ValidationException::withMessages([
                'quantity' => $source['code'].' rekeszből a védett és foglalt készlet mellett legfeljebb '.$source['movable_quantity'].' db helyezhető át.',
            ])->status(409);
        }

        $plan = $this->planLoad($placements, $data);

        return compact('warehouse', 'product', 'source', 'stock', 'placements', 'plan', 'target');
    }

    private function planLoad(Collection $placements, array $data): array
    {
        $remaining = $data['quantity'];
        $released = $knownWeight = $knownSlots = $sourceWeight = 0;
        $allRecorded = true;
        $sourceCheck = false;
        $allocations = [];
        foreach ($placements as $placement) {
            $taken = min($placement->quantity, $remaining);
            $full = $taken === $placement->quantity;
            $recorded = $full && !$placement->load_requires_verification;
            if ($full) $released += $placement->cargo_weight_grams;
            if ($recorded) {
                $knownWeight += $placement->cargo_weight_grams;
                $knownSlots += $placement->pallet_slots;
            }
            $allRecorded = $allRecorded && $recorded;
            $sourceCheck = $sourceCheck || $placement->load_requires_verification;
            $sourceWeight += $placement->cargo_weight_grams;
            $allocations[] = [
                'placement_id' => $placement->id, 'quantity' => $taken, 'remaining_quantity' => $placement->quantity - $taken,
                'prior_weight_grams' => $placement->cargo_weight_grams, 'prior_pallet_slots' => $placement->pallet_slots,
                'prior_load_requires_verification' => $placement->load_requires_verification,
                'recorded_moved_weight_grams' => $recorded ? $placement->cargo_weight_grams : null,
                'recorded_moved_pallet_slots' => $recorded ? $placement->pallet_slots : null,
                'retained_weight_grams' => $full ? 0 : $placement->cargo_weight_grams,
                'retained_pallet_slots' => $full ? 0 : $placement->pallet_slots,
                'remaining_load_requires_verification' => !$full,
            ];
            $remaining -= $taken;
            if ($remaining === 0) break;
        }

        $weight = $data['cargo_weight_grams'] ?? ($allRecorded ? $knownWeight : null);
        $slots = $data['pallet_slots'] ?? ($allRecorded ? $knownSlots : null);
        $errors = [];
        if ($weight !== null && ($weight < 1 || $weight > 1000000000
            || ($allRecorded ? $weight !== $knownWeight : $weight <= $knownWeight))) {
            $errors['cargo_weight_kg'] = $allRecorded
                ? 'A teljes tételek tömegének egyeznie kell az érvényes nyilvántartott terheléssel, legfeljebb 1 000 000 kg-ig.'
                : 'Add meg a teljes mozgatott rakomány tömegét, beleértve a teljes tételeket és a mért részletet.';
        } elseif ($weight !== null && !$sourceCheck && $weight > $sourceWeight) {
            $errors['cargo_weight_kg'] = 'A mozgatott rész nem lehet nehezebb a teljes nyilvántartott forrástételnél. Ellenőrizd a megadott tömeget.';
        }
        if ($slots !== null && ($slots > 1000000 || ($allRecorded ? $slots !== $knownSlots : $slots < $knownSlots))) {
            $errors['pallet_slots'] = 'A raklaphelyigénynek tartalmaznia kell a teljes, ellenőrzött tételek nyilvántartott raklaphelyeit.';
        }
        if ($errors) throw ValidationException::withMessages($errors);

        return ['allocations' => $allocations,
            'released_weight_grams' => $released, 'weight_grams' => $weight, 'pallet_slots' => $slots,
            'load_source' => $allRecorded ? 'recorded' : 'measured', 'source_check_required' => $sourceCheck];
    }

    private function assertCompleteCargo(array $plan, array $data): void
    {
        $errors = [];
        if ($plan['weight_grams'] === null) $errors['cargo_weight_kg'] = 'Add meg a mozgatott rész tényleges bruttó tömegét.';
        if ($plan['pallet_slots'] === null) $errors['pallet_slots'] = 'Add meg a tényleges raklaphelyigényt. Raklap nélküli rakományhoz adj meg 0-t.';
        if ($plan['source_check_required'] && !$data['source_load_verified']) $errors['source_load_verified'] = $this->sourceVerificationMessage();
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function sourceVerificationMessage(): string
    {
        return 'A forrástétel terhelése ellenőrzésre vár. Mérd fel a mozgatott rakományt, majd erősítsd meg a helyszíni ellenőrzést.';
    }

    private function invalidTarget(): never
    {
        throw ValidationException::withMessages(['to_location_id' => 'A célrekeszt a forrásraktárból válaszd ki.']);
    }

    private function assessmentInput(array $data, array $plan): array
    {
        return [
            'quantity' => $data['quantity'],
            'cargo_weight_kg' => $plan['weight_grams'] === null ? null : $plan['weight_grams'] / 1000,
            'pallet_slots' => $plan['pallet_slots'], 'dimensions_cm' => $data['dimensions_cm'],
        ];
    }

    private function cargoSummary(array $plan, array $data): array
    {
        return ['quantity' => $data['quantity'], 'cargo_weight_grams' => $plan['weight_grams'], 'pallet_slots' => $plan['pallet_slots'],
            'load_source' => $plan['load_source'], 'source_verification_required' => $plan['source_check_required'],
            'dimensions_cm' => $data['dimensions_cm'] ?: null];
    }

    private function validate(array $input, string $action): array
    {
        $write = $action === 'write';
        $integer = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_int($value) && !is_string($value)) $fail('A mezőbe egész számot adj meg.');
        };
        $data = Validator::make($input, [
            'warehouse_id' => 'required|integer|min:1', 'product_id' => 'required|integer|min:1',
            'from_location_id' => 'required|integer|min:1',
            'to_location_id' => [$action === 'candidates' ? 'nullable' : 'required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647', $integer],
            'cargo_weight_kg' => 'nullable|'.WarehouseCapacity::WEIGHT_RULE,
            'pallet_slots' => ['nullable', 'integer', 'min:0', 'max:1000000', $integer],
            'dimensions_cm' => 'nullable|array:width,depth,height',
            'dimensions_cm.*' => ['nullable', 'integer', 'min:1', 'max:65535', $integer],
            'source_revision' => 'nullable|regex:/\A[a-f0-9]{64}\z/',
            'target_revision' => 'nullable|regex:/\A[a-f0-9]{64}\z/',
            'source_load_verified' => 'sometimes|boolean',
            'site_verified' => 'sometimes|boolean',
        ] + ($action === 'candidates' ? [
            'mode' => 'sometimes|required|in:recommendations,search,cargo', 'search' => 'nullable|string|max:100',
        ] : []) + ($write ? [
            'operation_key' => 'required|string|uuid', 'confirmed' => 'required|accepted',
            'reason' => 'nullable|string|max:255', 'note' => 'nullable|string|max:500',
        ] : []), [
            'warehouse_id.*' => 'Válassz érvényes raktárt.', 'product_id.*' => 'Válassz érvényes terméket.',
            'from_location_id.*' => 'Válassz érvényes forrásrekeszt.', 'to_location_id.*' => 'Válassz érvényes célrekeszt.',
            'quantity.*' => 'Adj meg 1 és 2 147 483 647 közötti egész darabszámot.',
            'cargo_weight_kg.*' => 'Adj meg érvényes bruttó tömeget kilogrammban, legfeljebb három tizedesjeggyel.',
            'pallet_slots.*' => 'Adj meg 0 és 1 000 000 közötti egész raklaphelyigényt.',
            'dimensions_cm.*' => 'A rakomány méreteit centiméterben, pozitív egész számmal add meg.',
            'dimensions_cm.*.*' => 'A rakomány méreteit 1 és 65 535 közötti egész centiméterben add meg.',
            'source_revision.*' => 'Frissítsd a forrásrekesz állapotát.',
            'target_revision.*' => 'Ellenőrizd újra a célrekesz kapacitását.',
            'source_load_verified.*' => 'Jelöld, hogy a forrástétel terhelését a helyszínen ellenőrizted-e.',
            'site_verified.*' => 'Jelöld, hogy a célhelyi elférést a helyszínen ellenőrizted-e.',
            'mode.*' => 'Válassz ajánlást vagy kézi keresést.', 'search.*' => 'A keresés legfeljebb 100 karakter lehet.',
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'confirmed.*' => 'Erősítsd meg, hogy a fizikai áthelyezés megtörtént.',
            'reason.*' => 'Az indok legfeljebb 255 karakter lehet.',
            'note.*' => 'A megjegyzés legfeljebb 500 karakter lehet.',
        ])->validate();

        if (isset($data['to_location_id']) && (int) $data['to_location_id'] === (int) $data['from_location_id']) {
            throw ValidationException::withMessages(['to_location_id' => 'A forrás- és a célrekesz legyen különböző.']);
        }

        $dimensions = [];
        foreach (['width', 'depth', 'height'] as $axis) {
            if (isset($data['dimensions_cm'][$axis])) $dimensions[$axis] = (int) $data['dimensions_cm'][$axis];
        }
        $normalized = [
            'warehouse_id' => (int) $data['warehouse_id'], 'product_id' => (int) $data['product_id'],
            'from_location_id' => (int) $data['from_location_id'], 'to_location_id' => isset($data['to_location_id']) ? (int) $data['to_location_id'] : null,
            'quantity' => (int) $data['quantity'], 'cargo_weight_grams' => isset($data['cargo_weight_kg']) ? WarehouseCapacity::grams($data['cargo_weight_kg']) : null,
            'pallet_slots' => isset($data['pallet_slots']) ? (int) $data['pallet_slots'] : null, 'dimensions_cm' => $dimensions,
            'source_revision' => $data['source_revision'] ?? null, 'source_load_verified' => (bool) ($data['source_load_verified'] ?? false),
            'site_verified' => (bool) ($data['site_verified'] ?? false),
        ];
        if ($write && isset($data['target_revision'])) $normalized['target_revision'] = $data['target_revision'];
        if ($action === 'candidates') return $normalized + ['mode' => $data['mode'] ?? 'recommendations', 'search' => Str::trim($data['search'] ?? '')];

        if (!$write) return $normalized;

        $reason = Str::trim($data['reason'] ?? '');
        $note = Str::trim($data['note'] ?? '');

        return $normalized + ['operation_key' => strtolower($data['operation_key']),
            'reason' => $reason === '' ? null : $reason, 'note' => $note === '' ? null : $note];
    }
}
