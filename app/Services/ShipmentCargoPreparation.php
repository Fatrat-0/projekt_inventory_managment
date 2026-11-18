<?php

namespace App\Services;

use App\Models\{Product, ProductPackaging, Shipment, ShipmentAssignmentCargo, ShipmentCargoConfirmation, ShipmentIntentAssignment, TransferIntent, User, Vehicle, WarehouseTransferTask};
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentCargoPreparation
{
    public const MAX_WEIGHT_GRAMS = 1_000_000_000;
    public const MAX_PALLET_SLOTS = 2147483647;

    /** Use locked reads after acquiring the shipment write lock inside a transaction. */
    public function details(Shipment $shipment, Collection $assignments, bool $locked = false): array
    {
        if ($shipment->status !== 'draft' && ($dispatch = $shipment->dispatch()->first())) {
            return array_replace($dispatch->snapshot['cargo'], ['version' => $shipment->version, 'historical' => true, 'can_manage' => false]);
        }

        $vehicle = $shipment->vehicle_id === null ? null : $shipment->vehicle()->when($locked, fn ($query) => $query->sharedLock())->first();
        $confirmation = $shipment->cargoConfirmation()->when($locked, fn ($query) => $query->sharedLock())->first();

        $details = $this->assess($shipment, $this->rows($assignments, $locked), $vehicle, $confirmation);
        if ($confirmation) $details['confirmation']['confirmed_by_name'] = User::whereKey($confirmation->confirmed_by)->value('name');

        return $details;
    }

    public function transportSummaries(\Illuminate\Database\Eloquent\Collection $shipments): array
    {
        $candidates = $shipments->filter(fn (Shipment $shipment) => $shipment->vehicle_id !== null);
        $candidates->loadMissing('cargoConfirmation');
        $candidates = $candidates->filter(fn (Shipment $shipment) => $shipment->cargoConfirmation !== null);
        $candidates->loadMissing('vehicle');
        $rows = $this->rows($candidates->flatMap(fn (Shipment $shipment) => $shipment->intentAssignments), false);

        return $candidates->mapWithKeys(function (Shipment $shipment) use ($rows) {
            $cargo = $this->assess($shipment, $rows->only($shipment->intentAssignments->modelKeys()),
                $shipment->vehicle, $shipment->cargoConfirmation);

            return [$shipment->id => $cargo['cargo_ready'] && $cargo['vehicle_ready']];
        })->all();
    }

    public function saveItem(User $actor, Shipment $shipment, Collection $assignments, int $assignmentId, array $data): int
    {
        $assignment = $assignments->firstWhere('id', $assignmentId);
        abort_unless($assignment, 404, 'A tétel nem aktív hozzárendelése ennek a fuvarnak.');
        $item = $this->rows($assignments, true)->get($assignmentId)['item'];
        $suggestion = $item['suggestion'];
        $calculated = $data['source'] === 'calculated';
        if ($calculated) {
            if (!$suggestion || !hash_equals($suggestion['packaging_revision'], $data['packaging_revision'])) {
                throw new ConflictHttpException('A csomagolási javaslat már nem aktuális. Töltsd be a tételt újra.');
            }
            $weight = $suggestion['gross_weight_grams'];
        } else {
            $weight = $data['gross_weight_grams'];
            if ($data['confirmed'] && $weight === null) {
                throw ValidationException::withMessages(['gross_weight_grams' => 'Megerősítéshez add meg a tétel teljes bruttó tömegét.']);
            }
            if ($weight !== null && $suggestion && $suggestion['gross_weight_grams'] !== $weight && $data['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Indokold meg, miért tér el a kézzel megadott tömeg a számított javaslattól.']);
            }
        }

        // The shipment lock serializes every write to its cargo records, including first inserts.
        $cargo = $assignment->cargo()->sharedLock()->first() ?? $assignment->cargo()->make();
        $cargo->fill([
            'source' => $data['source'], 'gross_weight_grams' => $weight,
            'dimensions_cm' => $data['dimensions_cm'], 'packaging_note' => $data['packaging_note'],
            'packaging_basis' => $calculated ? $suggestion : null,
            'confirmed_by' => $data['confirmed'] ? $actor->id : null,
            'confirmed_at' => $data['confirmed'] ? now() : null,
        ])->save();
        $shipment->cargo_revision++;

        return (int) $assignment->transfer_intent_id;
    }

    public function confirm(User $actor, Shipment $shipment, Collection $assignments, array $data): void
    {
        if ($assignments->isEmpty()) {
            throw ValidationException::withMessages(['gross_weight_grams' => 'A rakomány megerősítéséhez legalább egy aktív tétel szükséges.']);
        }
        $cargo = $this->details($shipment, $assignments, true);
        if (!hash_equals($cargo['basis_revision'], $data['basis_revision'])) {
            throw new ConflictHttpException('A fuvar rakományának alapadatai megváltoztak. Ellenőrizd újra az aktuális adatokat.');
        }
        // Line weights exclude shared pallets/restraints already recorded as common materials.
        $minimum = $cargo['confirmed_item_weight_grams'] + ($data['shared_materials_weight_grams'] ?? 0);
        if ($data['gross_weight_grams'] < $minimum) {
            throw ValidationException::withMessages(['gross_weight_grams' => 'A teljes tömeg nem lehet kisebb a megerősített tételek és a külön megadott közös segédanyagok együttes tömegénél.']);
        }
        if ($cargo['all_items_confirmed'] && $data['gross_weight_grams'] !== $minimum && $data['reason'] === null) {
            throw ValidationException::withMessages(['reason' => 'Indokold meg, mi adja a teljes tömeg és az igazolt tételek, illetve a közös segédanyagok összegének eltérését.']);
        }

        $confirmation = $shipment->cargoConfirmation()->sharedLock()->first() ?? $shipment->cargoConfirmation()->make();
        $confirmation->fill($data + ['confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();
    }

    private function rows(Collection $assignments, bool $locked): Collection
    {
        if ($assignments->isEmpty()) return collect();

        $intentIds = $assignments->pluck('transfer_intent_id');
        // Picking locks the intent before the product. Product packaging edits also lock their product first.
        $intents = TransferIntent::whereKey($intentIds->all())->orderBy('id')
            ->when($locked, fn ($query) => $query->sharedLock())->get()->keyBy('id');
        $productIds = $intents->pluck('product_id')->unique()->values();
        if ($locked) {
            Product::whereKey($productIds->all())->orderBy('product_id')->sharedLock()->get(['product_id']);
        }
        $packages = ProductPackaging::whereIntegerInRaw('product_id', $productIds)
            ->when($locked, fn ($query) => $query->sharedLock())->get()->keyBy('product_id');
        $tasks = WarehouseTransferTask::whereIntegerInRaw('active_intent_id', $intentIds)
            ->when($locked, fn ($query) => $query->sharedLock())->get()->keyBy('transfer_intent_id');
        $saved = ShipmentAssignmentCargo::whereIntegerInRaw('shipment_intent_assignment_id', $assignments->pluck('id'))
            ->when($locked, fn ($query) => $query->sharedLock())->get()->keyBy('shipment_intent_assignment_id');

        return $assignments->sortBy('id')->mapWithKeys(function (ShipmentIntentAssignment $assignment) use ($intents, $packages, $tasks, $saved) {
            $intent = $intents->get($assignment->transfer_intent_id);
            $packaging = $packages->get($intent->product_id);
            $task = $tasks->get($intent->id);
            $itemCargo = $saved->get($assignment->id);
            $basis = $itemCargo?->source === 'calculated' ? TransferCargoWeight::packagingRevision($packaging) : null;

            return [$assignment->id => [
                'basis' => [$assignment->id, $intent->id, $intent->version, $intent->status, (int) $intent->product_id, $intent->quantity,
                    $task?->id, $task?->status, $task?->staged_quantity, $task?->expected_quantity, $task?->stock_id,
                    $task?->transfer_stock_reservation_id, $basis],
                'item' => [
                    'assignment_id' => $assignment->id, 'transfer_intent_id' => $intent->id, 'reference' => $intent->reference,
                    'product_id' => (int) $intent->product_id, 'quantity' => $intent->quantity,
                    'staged_quantity' => $task?->staged_quantity ?? 0, 'picking_status' => $task?->status,
                    'suggestion' => $this->suggestion($packaging, $intent->quantity),
                    'cargo' => $itemCargo ? $this->itemSnapshot($itemCargo, $basis) : null,
                ],
            ]];
        });
    }

    private function suggestion(?ProductPackaging $packaging, int $quantity): ?array
    {
        try {
            $weight = TransferCargoWeight::calculatedGrams($packaging, $quantity);
        } catch (ValidationException) {
            return null;
        }

        return [
            'gross_weight_grams' => $weight,
            'packaging_id' => $packaging->id, 'packaging_name' => $packaging->name,
            'packaging_revision' => TransferCargoWeight::packagingRevision($packaging),
            'pieces_per_package' => $packaging->pieces_per_package,
            'gross_weight_per_package_grams' => $packaging->grossWeightGrams(),
            'package_count' => intdiv($quantity, $packaging->pieces_per_package),
        ];
    }

    private function itemSnapshot(ShipmentAssignmentCargo $cargo, ?string $packagingRevision): array
    {
        $current = $cargo->source === 'manual' || $packagingRevision === ($cargo->packaging_basis['packaging_revision'] ?? null);

        return [
            'status' => !$current ? 'stale' : ($cargo->confirmed_at ? 'confirmed' : 'draft'),
            'source' => $cargo->source, 'gross_weight_grams' => $cargo->gross_weight_grams,
            'dimensions_cm' => $cargo->dimensions_cm, 'packaging_note' => $cargo->packaging_note,
            'packaging_basis' => $cargo->packaging_basis,
            'confirmed_by' => $cargo->confirmed_by, 'confirmed_at' => $cargo->confirmed_at?->toISOString(),
        ];
    }

    private function assess(Shipment $shipment, Collection $rows, ?Vehicle $vehicle, ?ShipmentCargoConfirmation $confirmation): array
    {
        $basis = hash('sha256', json_encode([
            (int) $shipment->id, $shipment->cargo_revision, $shipment->vehicle_id, $rows->pluck('basis')->all(),
        ], JSON_THROW_ON_ERROR));
        $items = $rows->pluck('item');
        $confirmedItems = $items->where('cargo.status', 'confirmed');
        $confirmedWeight = $confirmedItems->sum('cargo.gross_weight_grams');
        $validConfirmation = $confirmation && hash_equals($confirmation->basis_revision, $basis);
        $blockers = $this->cargoBlockers($items, $confirmation, $validConfirmation, $confirmedWeight);
        [$capacity, $vehicleBlockers] = $this->capacity($vehicle, $validConfirmation ? $confirmation : null);

        return [
            'shipment_id' => $shipment->id, 'version' => $shipment->version, 'basis_revision' => $basis,
            'vehicle' => $vehicle?->capacitySnapshot(), 'items' => $items->all(),
            'confirmation' => $confirmation ? [
                'status' => $validConfirmation ? 'confirmed' : 'stale', 'basis_revision' => $confirmation->basis_revision,
                'gross_weight_grams' => $confirmation->gross_weight_grams, 'pallet_slots' => $confirmation->pallet_slots,
                'shared_materials_weight_grams' => $confirmation->shared_materials_weight_grams,
                'confirmed_by' => $confirmation->confirmed_by, 'confirmed_at' => $confirmation->confirmed_at->toISOString(),
                'reason' => $confirmation->reason,
            ] : null,
            'confirmed_item_weight_grams' => $confirmedWeight,
            'all_items_confirmed' => $items->isNotEmpty() && $items->count() === $confirmedItems->count(),
            'cargo_ready' => $blockers === [], 'cargo_blockers' => $blockers,
            'vehicle_ready' => $vehicleBlockers === [] && $validConfirmation,
            'vehicle_blockers' => $vehicleBlockers, 'capacity' => $capacity,
        ];
    }

    private function cargoBlockers(Collection $items, ?ShipmentCargoConfirmation $confirmation, bool $validConfirmation, int $confirmedWeight): array
    {
        $blockers = [];
        if ($items->isEmpty()) {
            $blockers[] = ['code' => 'cargo_no_items', 'message' => 'A fuvarhoz még nincs rakománytétel rendelve.'];
        }
        foreach ($items->where('cargo.status', '!=', 'confirmed') as $item) {
            $stale = ($item['cargo']['status'] ?? null) === 'stale';
            $blockers[] = [
                'code' => $stale ? 'item_cargo_stale' : 'item_cargo_unconfirmed', 'field' => 'gross_weight_grams',
                'assignment_id' => $item['assignment_id'], 'transfer_intent_id' => $item['transfer_intent_id'],
                'message' => $stale ? $item['reference'].' tétel csomagolása megváltozott. Ellenőrizd újra a tételtömeget.'
                    : $item['reference'].' tétel bruttó tömegét még meg kell erősíteni.',
            ];
        }
        if (!$confirmation) {
            $blockers[] = ['code' => 'cargo_weight_unverified', 'field' => 'gross_weight_grams', 'message' => 'A teljes rakomány tömegét még meg kell erősíteni.'];
            $blockers[] = ['code' => 'pallet_requirement_unverified', 'field' => 'pallet_slots', 'message' => 'A teljes raklaphelyigényt még meg kell erősíteni.'];
        } elseif (!$validConfirmation) {
            $blockers[] = ['code' => 'cargo_confirmation_stale', 'field' => 'basis_revision', 'message' => 'A rakomány alapadatai megváltoztak. Erősítsd meg újra a teljes tömeget és a raklaphelyigényt.'];
        }
        if ($confirmation && $confirmation->gross_weight_grams < $confirmedWeight + ($confirmation->shared_materials_weight_grams ?? 0)) {
            $blockers[] = ['code' => 'cargo_weight_below_items', 'field' => 'gross_weight_grams', 'message' => 'A rögzített teljes tömeg kisebb a megerősített tételek és a közös segédanyagok együttes tömegénél.'];
        }

        return $blockers;
    }

    private function capacity(?Vehicle $vehicle, ?ShipmentCargoConfirmation $confirmation): array
    {
        $weight = $this->limit($vehicle?->payload_capacity_grams, $confirmation?->gross_weight_grams);
        $pallets = $this->limit($vehicle?->pallet_slots_capacity, $confirmation?->pallet_slots);
        $blockers = [];
        if (!$vehicle || !$vehicle->active) {
            $blockers[] = ['code' => $vehicle ? 'vehicle_inactive' : 'vehicle_missing', 'field' => 'vehicle_id',
                'message' => $vehicle ? 'A kiválasztott jármű inaktív.' : 'Válassz aktív, nyilvántartott járművet.'];
        } else {
            foreach (['weight' => $weight, 'pallet_slots' => $pallets] as $type => $limit) {
                if ($limit['limit'] === null) {
                    $blockers[] = ['code' => 'vehicle_'.$type.'_capacity_unknown', 'field' => $type === 'weight' ? 'payload_capacity_grams' : 'pallet_slots_capacity',
                        'message' => $type === 'weight' ? 'A jármű hasznos teherbírása nincs megadva.' : 'A jármű raklaphelykapacitása nincs megadva.'];
                } elseif ($limit['status'] === 'exceeded') {
                    $blockers[] = ['code' => 'vehicle_'.$type.'_capacity_exceeded', 'field' => $type === 'weight' ? 'gross_weight_grams' : 'pallet_slots',
                        'message' => $type === 'weight' ? 'A teljes rakomány meghaladja a jármű hasznos teherbírását.' : 'A szükséges raklaphelyek száma meghaladja a jármű raklaphelykapacitását.'];
                }
            }
        }

        return [[
            'weight_grams' => $weight, 'pallet_slots' => $pallets,
            'message' => 'Az ellenőrzés a tömeg- és raklaphelykorlátokra terjed ki. A fizikai elhelyezést és a biztonságos rakodást külön kell ellenőrizni.',
        ], $blockers];
    }

    private function limit(?int $limit, ?int $required): array
    {
        $remaining = $limit === null || $required === null ? null : $limit - $required;

        return ['limit' => $limit, 'required' => $required, 'remaining' => $remaining,
            'status' => $remaining === null ? 'unverified' : ($remaining < 0 ? 'exceeded' : 'within_limit')];
    }
}
