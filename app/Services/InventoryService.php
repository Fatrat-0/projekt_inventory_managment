<?php

namespace App\Services;

use App\Models\{InventoryMovement, InventoryTransfer, Partner, Product, ProductPackaging, ReturnQualityAction, ShipmentDispatch, ShipmentReceipt, ShipmentReceiptItem, Stock, User};
use App\Policies\TransferIntentPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;

/** All application stock writes use one transaction and the same product-row lock. */
class InventoryService
{
    public function __construct(
        private WarehouseCapacity $capacity,
        private SmartPicking $picking,
        private WarehouseStockAvailability $availability,
    ) {}

    /** Book an ordinary issue or import opening stock that has already been accepted for use. */
    public function recordMovement(array $data, ?string $requestKey = null, ?User $actor = null): InventoryMovement
    {
        return $this->bookMovement($data, $requestKey, false, $actor);
    }

    public function receiveManual(array $data, string $requestKey, ?User $actor = null): InventoryMovement
    {
        return $this->bookMovement(array_replace($data, ['type' => 'in']), $requestKey, true, $actor);
    }

    private function bookMovement(array $data, ?string $requestKey, bool $protectReceipt, ?User $actor): InventoryMovement
    {
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        $payload = [
            'product_id' => (int) $data['product_id'], 'warehouse_id' => (int) $data['warehouse_id'],
            'user_id' => (int) $data['user_id'], 'type' => $data['type'], 'quantity' => (int) $data['quantity'],
            'reference' => trim((string) ($data['reference'] ?? '')) === '' ? null : trim((string) $data['reference']),
        ];
        // Omit an empty partner from the hash so requests created before this feature still replay.
        if (isset($data['partner_id']) && $data['partner_id'] !== '') $payload['partner_id'] = (int) $data['partner_id'];
        $sources = null;
        if (array_key_exists('source_allocations', $data)) {
            if ($payload['type'] !== 'out') {
                throw ValidationException::withMessages(['source_allocations' => 'Forráshelyeket csak kézi készletkiadáshoz adhatsz meg.']);
            }
            $sources = $this->picking->normalizeIssueAllocations($data['source_allocations'], $payload['quantity']);
        }
        $hash = hash('sha256', json_encode($payload + ($sources === null ? [] : ['source_allocations' => $sources]), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($payload, $requestKey, $hash, $sources, $protectReceipt, $actor) {
                if ($actor) $this->authorizeMovement($actor, $payload);
                $requestId = null;
                if ($requestKey !== null) {
                    // The unique insert serializes the SAME request even across different products.
                    // This claim, the stock write, and the audit record commit or roll back together.
                    $requestId = DB::table('inventory_movement_requests')->insertGetId([
                        'user_id' => $payload['user_id'], 'request_key' => $requestKey,
                        'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->lockProduct($payload['product_id']);
                $partnerName = null;
                if (isset($payload['partner_id'])) {
                    // Archive/delete and movement creation serialize on the same partner row.
                    $partner = Partner::whereKey($payload['partner_id'])->lockForUpdate()->first();
                    if (!$partner || $partner->isArchived() || !$partner->supportsMovement($payload['type'])) {
                        throw ValidationException::withMessages(['partner_id' => 'Válassz aktív, a mozgás típusának megfelelő partnert (bevételezéshez beszállítót, kiadáshoz vevőt).']);
                    }
                    $partnerName = $partner->name;
                }
                if ($payload['type'] === 'out' || $protectReceipt) $this->capacity->lockWarehouses([$payload['warehouse_id']]);
                $picked = $sources === null ? [['quantity' => $payload['quantity']]] : $this->picking->withdrawForIssue(
                    $payload['warehouse_id'], $payload['product_id'], $payload['quantity'], $sources);
                // Withdrawing placements frees their pieces for this direct deduction under the same locks.
                $balances = $this->changeStock($payload['product_id'], $payload['warehouse_id'],
                    $payload['type'] === 'in' ? $payload['quantity'] : -$payload['quantity']);
                $movement = InventoryMovement::create(array_merge($payload, $balances, ['partner_name' => $partnerName]));
                if ($payload['type'] === 'out') $this->recordIssueSources($movement, $picked);
                if ($protectReceipt) {
                    $movement->manualReceiptBatch()->create([
                        'product_id' => $payload['product_id'], 'warehouse_id' => $payload['warehouse_id'],
                        'received_quantity' => $payload['quantity'], 'pending_putaway_quantity' => $payload['quantity'],
                    ]);
                }
                if ($requestId !== null) {
                    DB::table('inventory_movement_requests')->where('id', $requestId)
                        ->update(['movement_id' => $movement->id, 'updated_at' => now()]);
                }
                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return DB::transaction(function () use ($actor, $payload, $e, $requestKey, $hash) {
                if ($actor) $this->authorizeMovement($actor, $payload);

                return $this->replayMovement($e, $requestKey, $payload['user_id'], $hash);
            });
        }
    }

    private function authorizeMovement(User $actor, array $payload): void
    {
        $current = app(TransferWorkflowOperations::class)->currentActor($actor);
        abort_unless((int) $current->id === $payload['user_id'], 403);
        if ($payload['type'] === 'out') {
            app(TransferIntentPolicy::class)->authorizeManualIssue($current, $payload['warehouse_id']);
        }
    }

    private function recordIssueSources(InventoryMovement $movement, array $picked): void
    {
        $breakdown = collect($picked)->groupBy(fn (array $row) => $row['warehouse_location_id'] ?? 0)->map(function (Collection $source) {
            $first = $source->first();
            $locationId = $first['warehouse_location_id'] ?? null;

            return [
                'source_type' => $locationId === null ? 'unassigned' : 'location',
                'warehouse_location_id' => $locationId,
                'location_code' => $first['location_code'] ?? null,
                'location_name' => $first['location_name'] ?? null,
                'quantity' => $source->sum('quantity'),
            ];
        });
        if ($breakdown->sum('quantity') !== $movement->quantity) {
            throw new LogicException('A készletmozgás mennyisége eltér a kiadási források összegétől.');
        }
        $movement->issueSources()->createMany($breakdown->values()->all());
    }

    public function recordReturn(InventoryMovement $original, array $data, ?string $requestKey = null): InventoryMovement
    {
        foreach (['return_reason', 'reference'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) $data[$field] = Str::trim($data[$field]);
        }
        $validated = Validator::make($data, [
            'quantity' => 'required|integer|min:1|max:2147483647',
            'return_reason' => 'required|string|max:500', 'reference' => 'nullable|string|max:255',
        ], [], ['return_reason' => 'visszáru indoka'])->validate();
        $payload = ['operation' => 'return', 'return_of_id' => (int)$original->getKey(), 'user_id' => (int)$data['user_id'],
            'quantity' => (int)$validated['quantity'], 'return_reason' => $validated['return_reason'],
            'reference' => ($validated['reference'] ?? '') === '' ? null : $validated['reference']];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        try {
            return DB::transaction(function () use ($original, $payload, $requestKey, $hash) {
                $requestId = $requestKey === null ? null : DB::table('inventory_movement_requests')->insertGetId([
                    'user_id' => $payload['user_id'], 'request_key' => $requestKey, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                // Preserve the shared lock order: product, original movement/returns, partner, stock.
                $this->lockProduct((int)$original->product_id);
                $source = InventoryMovement::whereKey($original->getKey())->lockForUpdate()->firstOrFail();
                if (!$source->isReturnable() || (int)$source->product_id !== (int)$original->product_id) {
                    throw ValidationException::withMessages(['movement' => 'Visszáru csak eredeti készletmozgásból indítható.']);
                }
                // A locking read also sees concurrent commits inside an outer REPEATABLE READ transaction.
                $returned = (int)$source->returns()->lockForUpdate()->get(['quantity'])->sum('quantity');
                $remaining = max(0, $source->quantity - $returned);
                if ($payload['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['quantity' => 'A még visszaküldhető mennyiség legfeljebb '.$remaining.' db.']);
                }
                $partnerName = null;
                if ($source->partner_id !== null) {
                    // A historical return is valid even if this partner was archived or its role changed.
                    $partnerName = Partner::whereKey($source->partner_id)->lockForUpdate()->firstOrFail()->name;
                }
                $type = $source->type === 'in' ? 'out' : 'in';
                if ($type === 'out') $this->capacity->lockWarehouses([$source->warehouse_id]);
                $balances = $this->changeStock((int)$source->product_id, (int)$source->warehouse_id,
                    $type === 'in' ? $payload['quantity'] : -$payload['quantity'], $type === 'in' ? $payload['quantity'] : 0);
                $movement = InventoryMovement::create($balances + [
                    'product_id' => $source->product_id, 'warehouse_id' => $source->warehouse_id, 'partner_id' => $source->partner_id,
                    'partner_name' => $partnerName, 'type' => $type, 'quantity' => $payload['quantity'], 'user_id' => $payload['user_id'],
                    'return_of_id' => $source->id, 'return_reason' => $payload['return_reason'], 'reference' => $payload['reference'],
                    'quarantined_quantity' => $type === 'in' ? $payload['quantity'] : 0,
                ]);
                if ($requestId !== null) DB::table('inventory_movement_requests')->where('id', $requestId)->update(['movement_id' => $movement->id, 'updated_at' => now()]);
                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->replayMovement($e, $requestKey, $payload['user_id'], $hash);
        }
    }

    private function replayMovement(UniqueConstraintViolationException $exception, ?string $requestKey, int $userId, string $hash): InventoryMovement
    {
        if ($requestKey === null) throw $exception;
        // Shared current reads bypass old snapshots without upgrading duplicate-key shared locks.
        // Replay is read-only; exclusive lock upgrades can deadlock concurrent outer transactions.
        $previous = DB::table('inventory_movement_requests')->where('user_id', $userId)->where('request_key', $requestKey)->sharedLock()->first();
        if (!$previous || !$previous->movement_id) throw $exception;
        if (!hash_equals($previous->payload_hash, $hash)) {
            throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új művelethez nyiss új készletmozgás-űrlapot.']);
        }
        return InventoryMovement::whereKey($previous->movement_id)->sharedLock()->firstOrFail();
    }

    public function processReturnQuality(InventoryMovement $return, array $data, ?string $requestKey = null): ReturnQualityAction
    {
        if (isset($data['reason']) && is_string($data['reason'])) $data['reason'] = Str::trim($data['reason']);
        $validated = Validator::make($data, [
            'action' => 'required|in:release,scrap', 'quantity' => 'required|integer|min:1|max:2147483647',
            'reason' => 'required|string|max:500',
        ], [], ['reason' => 'minősítés indoka'])->validate();
        $payload = ['return_movement_id' => (int)$return->getKey(), 'user_id' => (int)$data['user_id'],
            'action' => $validated['action'], 'quantity' => (int)$validated['quantity'], 'reason' => $validated['reason']];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        try {
            return DB::transaction(function () use ($return, $payload, $requestKey, $hash) {
                // Lock before inserting the FK-linked action: concurrent inserts must not
                // hold shared return-row locks while waiting for this same product.
                $this->lockProduct((int)$return->product_id);
                // Keep the actor alive until commit; an account deleted while its form
                // was open must produce a validation error instead of a foreign-key error.
                if (!User::whereKey($payload['user_id'])->sharedLock()->first(['id'])) {
                    throw ValidationException::withMessages(['user' => 'A minősítést végző felhasználói fiók már nem érhető el.']);
                }
                // Claim first: an identical replay must succeed even after the last unit was processed.
                $action = ReturnQualityAction::create($payload + ['request_key' => $requestKey, 'payload_hash' => $hash,
                    'stock_before' => 0, 'stock_after' => 0, 'quarantined_before' => 0, 'quarantined_after' => 0]);
                $source = InventoryMovement::whereKey($return->getKey())->lockForUpdate()->firstOrFail();
                if (!$source->isReturn() || $source->type !== 'in' || $source->quarantined_quantity <= 0
                    || (int)$source->product_id !== (int)$return->product_id) {
                    throw ValidationException::withMessages(['movement' => 'Csak elkülönítve bevételezett vevői visszáru minősíthető.']);
                }
                // The product lock serializes claims for this source, so all previous
                // actions have smaller IDs. Stop before our own claim: an unbounded
                // id != scan can lock the next source's in-flight FK index entry and
                // deadlock with its duplicate request-key check under REPEATABLE READ.
                $processed = (int)$source->qualityActions()->where('id', '<', $action->id)->lockForUpdate()->get(['quantity'])->sum('quantity');
                $remaining = max(0, $source->quarantined_quantity - $processed);
                if ($payload['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['quantity' => 'A még minősíthető mennyiség legfeljebb '.$remaining.' db.']);
                }
                $stock = Stock::where('product_id', $source->product_id)->where('warehouse_id', $source->warehouse_id)->lockForUpdate()->firstOrFail();
                if ($stock->quarantined_quantity < $payload['quantity']) {
                    throw ValidationException::withMessages(['quantity' => 'Nincs elegendő elkülönített készlet a művelethez.']);
                }
                $before = $stock->quantity;
                $quarantinedBefore = $stock->quarantined_quantity;
                $scrap = $payload['action'] === 'scrap';
                $balances = $this->changeStock((int)$source->product_id, (int)$source->warehouse_id,
                    $scrap ? -$payload['quantity'] : 0, -$payload['quantity']);
                $movement = $scrap ? InventoryMovement::create($balances + [
                    'product_id' => $source->product_id, 'warehouse_id' => $source->warehouse_id, 'user_id' => $payload['user_id'],
                    'type' => 'out', 'quantity' => $payload['quantity'], 'reference' => 'Selejtezés', 'scrap_of_id' => $source->id,
                ]) : null;
                $action->update(['movement_id' => $movement?->id, 'stock_before' => $before, 'stock_after' => $balances['stock_after'],
                    'quarantined_before' => $quarantinedBefore, 'quarantined_after' => $quarantinedBefore - $payload['quantity']]);
                return $action;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($requestKey === null) throw $e;
            $previous = ReturnQualityAction::where('user_id', $payload['user_id'])->where('request_key', $requestKey)->sharedLock()->first();
            if (!$previous) throw $e;
            if (!hash_equals($previous->payload_hash, $hash)) {
                throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új minősítéshez frissítsd az oldalt.']);
            }
            return $previous;
        }
    }

    public function startTransfer(array $data, ?string $requestKey = null): InventoryTransfer
    {
        $payload = ['product_id' => (int)$data['product_id'], 'from_warehouse_id' => (int)$data['from_warehouse_id'],
            'to_warehouse_id' => (int)$data['to_warehouse_id'], 'quantity' => (int)$data['quantity'], 'user_id' => (int)$data['user_id']];
        if (isset($data['replenishment_stock_id'])) $payload['replenishment_stock_id'] = (int)$data['replenishment_stock_id'];
        $payload += WarehouseCapacity::cargo($data);
        $boxCount = isset($data['box_count']) ? (int) $data['box_count'] : null;
        $calculatedWeight = ($data['cargo_weight_source'] ?? null) === TransferCargoWeight::SOURCE_CALCULATED;
        $packagingRevision = $data['cargo_weight_packaging'] ?? null;
        $sources = array_key_exists('source_allocations', $data)
            ? $this->picking->normalizeAllocations($data['source_allocations'], $payload['quantity'])
            : [];
        $hashData = $payload;
        // An explicit unassigned-only pick is equivalent to the legacy implicit pick.
        if ($sources !== [] && (count($sources) > 1 || $sources[0]['location_id'] !== null)) {
            $hashData['source_allocations'] = $sources;
        }
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        try {
            return DB::transaction(function () use ($payload, $sources, $requestKey, $hash, $boxCount, $calculatedWeight, $packagingRevision) {
                $requestId = $requestKey === null ? null : DB::table('inventory_transfer_requests')->insertGetId([
                    'user_id' => $payload['user_id'], 'request_key' => $requestKey, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $product = $this->lockProduct($payload['product_id']);
                if ($boxCount !== null || $calculatedWeight || $packagingRevision !== null) {
                    $packaging = $product->defaultPackaging()->lockForUpdate()->first();
                    if ($packagingRevision !== null) {
                        TransferCargoWeight::assertPackagingRevision($packaging, $packagingRevision);
                    }
                    if ($boxCount !== null) $this->assertBoxQuantity($packaging, $boxCount, $payload['quantity']);
                    if ($calculatedWeight) {
                        TransferCargoWeight::assertCalculated($packaging, $payload['quantity'], $payload['cargo_weight_grams'] ?? null);
                    }
                }
                $this->capacity->lockWarehouses([$payload['from_warehouse_id'], $payload['to_warehouse_id']]);
                $this->capacity->checkReservation($payload, (int) $product->category_id);
                if (isset($payload['replenishment_stock_id'])) $this->checkReplenishment($payload);
                $picked = $sources === [] ? [] : $this->picking->withdraw(
                    $payload['from_warehouse_id'], $payload['product_id'], $payload['quantity'], $sources,
                );
                $this->changeStock($payload['product_id'], $payload['from_warehouse_id'], -$payload['quantity']);
                $transferData = $payload;
                unset($transferData['replenishment_stock_id']);
                $transfer = InventoryTransfer::create(array_merge($transferData, ['status' => 'pending']));
                if ($picked !== []) $transfer->sourceAllocations()->createMany($picked);
                if ($requestId !== null) {
                    DB::table('inventory_transfer_requests')->where('id', $requestId)->update(['transfer_id' => $transfer->id]);
                }
                return $transfer;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($requestKey === null) throw $e;
            $previous = DB::table('inventory_transfer_requests')->where('user_id', $payload['user_id'])->where('request_key', $requestKey)->sharedLock()->first();
            if (!$previous || !$previous->transfer_id) throw $e;
            if (!hash_equals($previous->payload_hash, $hash)) throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új átmozgatáshoz nyiss új űrlapot.']);
            return InventoryTransfer::whereKey($previous->transfer_id)->sharedLock()->firstOrFail();
        }
    }

    private function assertBoxQuantity(?ProductPackaging $packaging, int $boxCount, int $quantity): void
    {
        $piecesPerBox = $packaging?->pieces_per_package ?? 0;
        if ($piecesPerBox < 1) {
            throw ValidationException::withMessages(['box_count' => 'A termékhez nincs érvényes csomagolás. Add meg a mennyiséget darabban.']);
        }
        if ($boxCount < 1 || $boxCount > intdiv(2147483647, $piecesPerBox)) {
            throw ValidationException::withMessages(['box_count' => 'A dobozokból számolt mennyiség 1 és 2 147 483 647 db között lehet.']);
        }
        if ($boxCount * $piecesPerBox !== $quantity) {
            throw ValidationException::withMessages(['box_count' => 'A megadott darabszám eltér az aktuális csomagolásból számolt mennyiségtől. Ellenőrizd a dobozszámot.']);
        }
    }

    private function checkReplenishment(array $payload): void
    {
        // Locking reads stay current even inside an already-open REPEATABLE READ transaction.
        $target = Stock::whereKey($payload['replenishment_stock_id'])->where('product_id', $payload['product_id'])
            ->where('warehouse_id', $payload['to_warehouse_id'])->lockForUpdate()->first();
        $source = Stock::where('product_id', $payload['product_id'])->where('warehouse_id', $payload['from_warehouse_id'])->lockForUpdate()->first();
        $incoming = InventoryTransfer::where('product_id', $payload['product_id'])->where('to_warehouse_id', $payload['to_warehouse_id'])
            ->where('status', 'pending')->lockForUpdate()->get(['quantity'])->sum('quantity');
        $pending = $target ? $this->availability->snapshotForUpdate($target->warehouse_id, $target->product_id)['pending_putaway_quantity'] : 0;
        $targetUsable = $target ? $target->quantity - $target->quarantined_quantity - $pending : 0;
        $shortage = $target ? $target->min_quantity - $targetUsable - $incoming : 0;
        $available = $source ? $this->availability->snapshotForUpdate($source->warehouse_id, $source->product_id) : null;
        $surplus = empty($available['consistent']) ? 0
            : min($available['direct_issue_quantity'], $available['free_quantity'] - $source->min_quantity);
        if ($payload['from_warehouse_id'] === $payload['to_warehouse_id'] || !$target || !$source || $payload['quantity'] > min($shortage, $surplus)) {
            throw ValidationException::withMessages(['quantity' => 'Az utánpótlási javaslat közben megváltozott. A mennyiség nem haladhatja meg a cél aktuális hiányát és a forrás helyi minimum feletti kiadható készletét. Frissítsd a vezérlőpultot.']);
        }
    }

    /** @return int Quantity from former bins requiring re-placement after cancellation. */
    public function finalizeTransfer(InventoryTransfer $transfer, bool $complete, bool $shipmentManaged = false): int
    {
        return DB::transaction(function () use ($transfer, $complete, $shipmentManaged) {
            // Transfer parties and product are immutable. Lock their warehouses before touching capacity or stock.
            $product = $this->lockProduct((int) $transfer->product_id);
            $this->capacity->lockWarehouses([$transfer->from_warehouse_id, $transfer->to_warehouse_id]);
            $locked = InventoryTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            if (!$shipmentManaged && \App\Models\ShipmentItem::where('transfer_id', $locked->id)->sharedLock()->first(['id'])) {
                throw ValidationException::withMessages(['transfer' => 'Ez a tétel szállítmányhoz tartozik. Az átvételt vagy visszavonást a szállítmány oldalán végezd.']);
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['transfer' => 'Csak úton lévő átmozgatás vehető át vagy vonható vissza.']);
            }
            $warehouseId = $complete ? $locked->to_warehouse_id : $locked->from_warehouse_id;
            $this->changeStock((int) $locked->product_id, (int) $warehouseId, (int) $locked->quantity);
            $needsPlacement = 0;
            if ($complete && $locked->to_location_id !== null) {
                $stock = Stock::where('product_id', $locked->product_id)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
                $this->capacity->receive($locked, $stock);
            } elseif (!$complete) {
                foreach ($locked->sourceAllocations()->whereNotNull('location_code')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                    $restored = $this->capacity->restorePickedAllocation($allocation, (int) $warehouseId, (int) $product->category_id);
                    $allocation->update(['returned_to_location' => $restored]);
                    if (!$restored) $needsPlacement += $allocation->quantity;
                }
            }
            $locked->update(['status' => $complete ? 'completed' : 'cancelled']);

            return $needsPlacement;
        }, 3);
    }

    private function lockProduct(int $productId): Product
    {
        // A stock row may not exist yet. The product always exists, so its lock also
        // serializes concurrent first receipts and creation of destination stock.
        return Product::whereKey($productId)->lockForUpdate()->firstOrFail();
    }

    /** Post only transit-backed issues after dispatch has validated and locked its preparation. */
    public function bookShipmentDispatch(ShipmentDispatch $dispatch, Collection $items, array $stocks): void
    {
        if (DB::transactionLevel() === 0 || !$dispatch->exists || $items->isEmpty()) {
            throw new LogicException('A fuvar készletkönyveléséhez rögzített feladás és aktív tranzakció szükséges.');
        }

        $origin = $dispatch->snapshot['shipment']['origin_warehouse_id'];
        foreach ($items->groupBy('product_id')->sortKeys() as $productId => $lines) {
            $expected = $stocks[$productId] ?? [];
            $quantity = $lines->sum('dispatched_quantity');
            if (!($expected['consistent'] ?? false) || $quantity <= 0 || $quantity > $expected['staged_quantity']
                || $lines->contains(fn ($line) => !$line->exists || $line->shipment_dispatch_id !== $dispatch->id || $line->from_warehouse_id !== $origin)) {
                throw ValidationException::withMessages(['dispatch' => 'A feladandó tételek és a védett forráskészlet nem egyeznek.'])->status(409);
            }

            $stock = Stock::whereKey($expected['stock_id'])->where('product_id', $productId)->where('warehouse_id', $origin)->lockForUpdate()->first();
            if (!$stock || $stock->quantity !== $expected['physical_quantity']) {
                throw ValidationException::withMessages(['dispatch' => 'A forráskészlet adatai időközben megváltoztak.'])->status(409);
            }

            $balance = $stock->quantity;
            $stock->update(['quantity' => $balance - $quantity]);
            foreach ($lines as $line) {
                $after = $balance - $line->dispatched_quantity;
                InventoryMovement::create([
                    'shipment_transit_item_id' => $line->id, 'product_id' => $productId, 'warehouse_id' => $origin,
                    'user_id' => $dispatch->actor_id, 'type' => 'out', 'quantity' => $line->dispatched_quantity,
                    'reference' => 'Fuvarfeladás · '.$dispatch->snapshot['shipment']['shipment_number'],
                    'stock_before' => $balance, 'stock_after' => $after,
                ]);
                $balance = $after;
            }
        }
    }

    /** Book the received units under the product, destination warehouse and stock locks held by the receipt transaction. */
    public function bookShipmentReceipt(ShipmentReceipt $receipt, Collection $items): void
    {
        if (DB::transactionLevel() === 0 || !$receipt->exists || $items->isEmpty()) {
            throw new LogicException('A készletnöveléshez rögzített fuvarátvétel és aktív tranzakció szükséges.');
        }

        $warehouseId = (int) $receipt->to_warehouse_id;
        $reference = 'Fuvarátvétel · '.$receipt->shipment->shipment_number;
        foreach ($items->sortBy([['product_id', 'asc'], ['id', 'asc']]) as $item) {
            if (!($item instanceof ShipmentReceiptItem) || !$item->exists || (int) $item->shipment_receipt_id !== $receipt->id
                || (int) $item->to_warehouse_id !== $warehouseId || $item->received_quantity < 1
                || $item->pending_putaway_quantity !== $item->received_quantity) {
                throw ValidationException::withMessages(['receipt' => 'Az átvételi tétel és a betárolásra váró mennyiség nem egyezik az átvétel adataival.'])->status(409);
            }

            $balances = $this->changeStock($item->product_id, $warehouseId, $item->received_quantity);
            InventoryMovement::create($balances + [
                'shipment_receipt_item_id' => $item->id, 'product_id' => $item->product_id, 'warehouse_id' => $warehouseId,
                'user_id' => $receipt->actor_id, 'type' => 'in', 'quantity' => $item->received_quantity, 'reference' => $reference,
            ]);
        }
    }

    private function changeStock(int $productId, int $warehouseId, int $delta, int $quarantineDelta = 0): array
    {
        $stock = Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->lockForUpdate()->first();
        $before = (int) ($stock?->quantity ?? 0);
        $quantity = $before + $delta;
        $quarantined = (int)($stock?->quarantined_quantity ?? 0) + $quarantineDelta;
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'Nincs elegendő készlet a kiválasztott raktárban.']);
        }
        if ($quantity > 2147483647) {
            throw ValidationException::withMessages(['quantity' => 'A készlet meghaladná a megengedett legnagyobb mennyiséget.']);
        }
        if ($quarantined < 0 || $quarantined > $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Nincs elegendő kiadható készlet a kiválasztott raktárban. Az elkülönített visszáru előbb minősítendő.']);
        }
        if ($stock && $delta < $quarantineDelta) {
            $available = $this->availability->snapshotForUpdate($warehouseId, $productId);
            if (!$available['consistent']) {
                throw ValidationException::withMessages(['quantity' => 'A raktári készlet, a tárhelyek vagy a foglalások adatai eltérnek. Ellenőrizd a készletet.']);
            }
            $requested = $quarantineDelta - $delta;
            if ($available['pending_putaway_quantity'] > 0 && $requested > $available['free_quantity']) {
                throw ValidationException::withMessages(['quantity' => 'A betárolásra váró készlet védett. Legfeljebb '.$available['free_quantity'].' db szabad készlet használható fel.']);
            }
            if ($available['source_reserved_quantity'] + $available['staged_quantity'] > 0 && $requested > $available['free_quantity']) {
                throw ValidationException::withMessages(['quantity' => 'A foglalt és összekészített tételek mellett legfeljebb '.$available['free_quantity'].' db szabad készlet használható fel.']);
            }
            if ($requested > $available['direct_issue_quantity']) {
                throw ValidationException::withMessages(['quantity' => 'Nincs elegendő tárhely nélküli kiadható készlet. A kiadandó tételeket előbb vedd ki a tárhelyükről.']);
            }
        }
        if ($stock) {
            $stock->update(['quantity' => $quantity, 'quarantined_quantity' => $quarantined]);
        } else {
            Stock::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'quarantined_quantity' => $quarantined]);
        }
        return ['stock_before' => $before, 'stock_after' => $quantity];
    }
}
