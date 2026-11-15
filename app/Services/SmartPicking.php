<?php

namespace App\Services;

use App\Models\{TransferStockReservation, WarehousePlacement, WarehouseTransferTask};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SmartPicking
{
    public function __construct(private WarehouseStockAvailability $availability)
    {
    }

    /**
     * Read-only source snapshot. Pending departures have already been deducted;
     * pending arrivals reserve space but are not yet part of this stock.
     */
    public function options(int $warehouseId, int $productId, ?int $quantity = null): array
    {
        $stock = $this->availability->snapshot($warehouseId, $productId);
        $available = $stock['pickable_quantity'] ?? 0;

        return $this->buildOptions($stock, $available, $quantity);
    }

    /** A valid task can use its own hold up to its remaining quantity. */
    public function reservedOptions(WarehouseTransferTask $task, TransferStockReservation $reservation, int $productId, int $quantity): array
    {
        $stock = $this->availability->snapshot($task->warehouse_id, $productId);
        $available = $this->reservedLimit($stock, $task, $reservation);
        $options = $this->buildOptions($stock, $available, null);
        foreach ($options['sources'] as &$source) {
            $source['available_quantity'] = min($source['available_quantity'], $available);
        }
        unset($source);
        $bins = array_filter($options['sources'], fn ($source) => $source['location_id'] !== null && $source['active']);
        $options['suggestion'] = $this->suggestions($bins, min($quantity, $available));

        return $options;
    }

    private function buildOptions(array $stock, int $available, ?int $quantity): array
    {
        $sources = $this->sourceRows($stock, $available);

        return [
            'available_quantity' => $available,
            'source_reserved_quantity' => $stock['source_reserved_quantity'],
            'staged_quantity' => $stock['staged_quantity'],
            'free_quantity' => $stock['free_quantity'],
            'quarantined_quantity' => $stock['quarantined_quantity'],
            'sources' => $sources,
            // The legacy transfer workflow can suggest unassigned stock first.
            'suggestion' => $quantity !== null && $quantity <= $available ? $this->suggestions($sources, $quantity) : [],
            'consistent' => $stock['consistent'],
        ];
    }

    private function suggestions(array $sources, int $remaining): array
    {
        $suggestions = [];
        foreach ($sources as $source) {
            if ($remaining <= 0) break;
            $picked = min($remaining, $source['available_quantity']);
            if ($picked > 0) {
                $suggestions[] = ['location_id' => $source['location_id'], 'quantity' => $picked];
                $remaining -= $picked;
            }
        }

        return $suggestions;
    }

    /** @return list<array{location_id: ?int, quantity: int}> */
    public function normalizeAllocations(mixed $input, int $quantity): array
    {
        $data = Validator::make(['source_allocations' => $input], [
            'source_allocations' => 'required|array|list',
            'source_allocations.*' => 'required|array:location_id,quantity',
            'source_allocations.*.location_id' => 'present|nullable|integer|min:1',
            'source_allocations.*.quantity' => 'required|integer|min:1|max:2147483647',
        ], [
            'source_allocations.*' => 'Adj meg érvényes forráshelyeket és pozitív egész darabszámokat.',
        ])->validate();
        $allocations = [];
        foreach ($data['source_allocations'] as $row) {
            $locationId = $row['location_id'] === null ? null : (int) $row['location_id'];
            if ($locationId === 0) {
                throw ValidationException::withMessages(['source_allocations' => 'Válassz érvényes forráshelyet.']);
            }
            $key = $locationId ?? 0;
            if (isset($allocations[$key])) {
                throw ValidationException::withMessages(['source_allocations' => 'Egy forráshely csak egyszer szerepelhet.']);
            }
            $allocations[$key] = ['location_id' => $locationId, 'quantity' => (int) $row['quantity']];
        }
        if (array_sum(array_column($allocations, 'quantity')) !== $quantity) {
            throw ValidationException::withMessages(['source_allocations' => 'A kitárolandó darabszám egyezzen meg az átmozgatás mennyiségével.']);
        }

        ksort($allocations, SORT_NUMERIC);

        return array_values($allocations);
    }

    /**
     * Take only the assigned quantities, after the caller locks the product and
     * warehouses. InventoryService books the warehouse-level deduction once.
     */
    public function withdraw(int $warehouseId, int $productId, int $quantity, array $allocations): array
    {
        $allocations = $this->normalizeAllocations($allocations, $quantity);
        $stock = $this->availability->snapshotForUpdate($warehouseId, $productId);
        $this->assertConsistent($stock);
        if (!$stock['stock_id'] || $quantity > $stock['physical_quantity'] - $stock['quarantined_quantity']) {
            throw ValidationException::withMessages(['quantity' => 'Nincs elegendő kiadható készlet a forrásraktárban.']);
        }
        if ($stock['source_reserved_quantity'] + $stock['staged_quantity'] > 0 && $quantity > $stock['pickable_quantity']) {
            throw ValidationException::withMessages(['quantity' => 'A foglalt és összekészített tételek mellett legfeljebb '.$stock['pickable_quantity'].' db tárolható ki.']);
        }

        return $this->takeFromSources($stock, $allocations, $stock['pickable_quantity'], false);
    }

    /** Move out of bins while a picking command holds the task and stock locks. */
    public function withdrawReserved(WarehouseTransferTask $task, TransferStockReservation $reservation, int $productId, int $quantity, array $allocations): array
    {
        $stock = $this->availability->snapshotForUpdate($task->warehouse_id, $productId);
        $this->assertConsistent($stock);
        $limit = $this->reservedLimit($stock, $task, $reservation);
        if ($quantity > $limit) {
            throw ValidationException::withMessages(['quantity' => 'Ehhez a feladathoz legfeljebb '.$limit.' db tárolható még ki.']);
        }

        return $this->takeFromSources($stock, $this->normalizeAllocations($allocations, $quantity), $limit, true);
    }

    private function assertConsistent(array $stock): void
    {
        if (!$stock['consistent']) {
            throw ValidationException::withMessages(['source_allocations' => 'A forrásraktár készlet- és tárhelyadatai eltérnek. Kitárolás előtt ellenőrizd a készletet.']);
        }
    }

    private function reservedLimit(array $stock, WarehouseTransferTask $task, TransferStockReservation $reservation): int
    {
        if (!$stock['consistent'] || $reservation->status !== TransferStockReservation::ACTIVE
            || !in_array($task->status, [WarehouseTransferTask::PENDING, WarehouseTransferTask::IN_PROGRESS], true)
            || $reservation->transfer_intent_id !== $task->transfer_intent_id
            || $reservation->id !== $task->transfer_stock_reservation_id || $reservation->stock_id !== $task->stock_id
            || $stock['stock_id'] !== $task->stock_id || $reservation->remaining_quantity > $stock['source_reserved_quantity']) {
            return 0;
        }

        return max(0, min($reservation->remaining_quantity, $task->expected_quantity - $task->staged_quantity));
    }

    private function takeFromSources(array $stock, array $allocations, int $limit, bool $retainUnverifiedLoad): array
    {
        $sources = collect($this->sourceRows($stock, $limit))->keyBy(fn (array $source) => $source['location_id'] ?? 0);
        foreach ($allocations as $allocation) {
            $source = $sources->get($allocation['location_id'] ?? 0);
            if (!$source || $allocation['quantity'] > $source['available_quantity']) {
                $name = $source['code'] ?? ($allocation['location_id'] === null ? 'tárhely nélküli készlet' : 'választott tárhely');
                throw ValidationException::withMessages(['source_allocations' => 'A(z) '.$name.' nem rendelkezik a megadott kiadható mennyiséggel. Ellenőrizd a friss készletet.']);
            }
        }

        $locationIds = array_filter(array_column($allocations, 'location_id'));
        $placementsByLocation = WarehousePlacement::where('stock_id', $stock['stock_id'])
            ->whereIn('warehouse_location_id', $locationIds)->orderBy('id')->lockForUpdate()->get()->groupBy('warehouse_location_id');
        $picked = [];
        foreach ($allocations as $allocation) {
            $locationId = $allocation['location_id'];
            $remaining = $allocation['quantity'];
            if ($locationId === null) {
                $picked[] = ['stock_id' => $stock['stock_id'], 'quantity' => $remaining];
                continue;
            }
            foreach ($placementsByLocation[$locationId] as $placement) {
                $taken = min($remaining, $placement->quantity);
                $picked[] = $this->takeFromPlacement($placement, $taken, $sources[$locationId], $retainUnverifiedLoad);
                $remaining -= $taken;
                if ($remaining === 0) break;
            }
        }

        return $picked;
    }

    private function sourceRows(array $stock, int $limit): array
    {
        // Warehouse-level holds are included in the overall limit, without attributing them to a source row.
        $unassigned = [
            'location_id' => null, 'code' => null, 'name' => 'Tárhely nélküli készlet',
            'stored_quantity' => max(0, $stock['unassigned_quantity']),
            'reserved_quantity' => 0,
            'available_quantity' => min($limit, $stock['unassigned_usable_quantity'] ?? 0),
            'active' => true,
        ];
        $bins = array_map(fn (array $location) => array_replace($location, [
            'reserved_quantity' => $location['reserved_quantity'] ?? 0,
            'available_quantity' => $location['available_quantity'] ?? 0,
        ]), $stock['locations']);
        usort($bins, fn (array $a, array $b) => ($b['available_quantity'] <=> $a['available_quantity'])
            ?: strcmp($a['code'], $b['code']) ?: ($a['location_id'] <=> $b['location_id']));

        return [$unassigned, ...$bins];
    }

    private function takeFromPlacement(WarehousePlacement $placement, int $quantity, array $source, bool $retainUnverifiedLoad): array
    {
        $full = $quantity === $placement->quantity;
        // Whole grams are conserved; the remaining stock retains rounding remainders.
        $verified = !$placement->load_requires_verification && ($full || !$retainUnverifiedLoad);
        $grams = $verified ? intdiv($placement->cargo_weight_grams * $quantity, $placement->quantity) : null;
        // Without per-pallet contents, only a full pick proves pallet slots were freed.
        $slots = $verified ? ($full ? $placement->pallet_slots : 0) : null;
        $allocation = [
            'stock_id' => $placement->stock_id, 'warehouse_location_id' => $placement->warehouse_location_id,
            'source_placement_id' => $placement->id, 'location_code' => $source['code'],
            'location_name' => $source['name'], 'quantity' => $quantity,
            'cargo_weight_grams' => $grams, 'pallet_slots' => $slots,
        ];
        if ($full) {
            $placement->delete();
        } else {
            $placement->update([
                'quantity' => $placement->quantity - $quantity,
                'cargo_weight_grams' => $placement->cargo_weight_grams - ($grams ?? 0),
                'load_requires_verification' => !$verified,
            ]);
        }

        return $allocation;
    }
}
