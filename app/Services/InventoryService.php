<?php

namespace App\Services;

use App\Models\{InventoryMovement, InventoryTransfer, Partner, Product, ReturnQualityAction, Stock, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** All application stock writes use one transaction and the same product-row lock. */
class InventoryService
{
    public function recordMovement(array $data, ?string $requestKey = null): InventoryMovement
    {
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        $payload = [
            'product_id' => (int) $data['product_id'], 'warehouse_id' => (int) $data['warehouse_id'],
            'user_id' => (int) $data['user_id'], 'type' => $data['type'], 'quantity' => (int) $data['quantity'],
            'reference' => trim((string) ($data['reference'] ?? '')) === '' ? null : trim((string) $data['reference']),
        ];
        // Omit an empty partner from the hash so requests created before this feature still replay.
        if (isset($data['partner_id']) && $data['partner_id'] !== '') $payload['partner_id'] = (int) $data['partner_id'];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($payload, $requestKey, $hash) {
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
                $balances = $this->changeStock($payload['product_id'], $payload['warehouse_id'],
                    $payload['type'] === 'in' ? $payload['quantity'] : -$payload['quantity']);
                $movement = InventoryMovement::create(array_merge($payload, $balances, ['partner_name' => $partnerName]));
                if ($requestId !== null) {
                    DB::table('inventory_movement_requests')->where('id', $requestId)
                        ->update(['movement_id' => $movement->id, 'updated_at' => now()]);
                }
                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->replayMovement($e, $requestKey, $payload['user_id'], $hash);
        }
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
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $requestKey = $requestKey !== null ? strtolower($requestKey) : null;
        try {
            return DB::transaction(function () use ($payload, $requestKey, $hash) {
                $requestId = $requestKey === null ? null : DB::table('inventory_transfer_requests')->insertGetId([
                    'user_id' => $payload['user_id'], 'request_key' => $requestKey, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->lockProduct($payload['product_id']);
                $this->changeStock($payload['product_id'], $payload['from_warehouse_id'], -$payload['quantity']);
                $transfer = InventoryTransfer::create(array_merge($payload, ['status' => 'pending']));
                if ($requestId !== null) {
                    DB::table('inventory_transfer_requests')->where('id', $requestId)->update(['transfer_id' => $transfer->id]);
                }
                return $transfer;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($requestKey === null) throw $e;
            $previous = DB::table('inventory_transfer_requests')->where('user_id', $payload['user_id'])->where('request_key', $requestKey)->first();
            if (!$previous || !$previous->transfer_id) throw $e;
            if (!hash_equals($previous->payload_hash, $hash)) throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új átmozgatáshoz nyiss új űrlapot.']);
            return InventoryTransfer::findOrFail($previous->transfer_id);
        }
    }

    public function finalizeTransfer(InventoryTransfer $transfer, bool $complete, bool $shipmentManaged = false): void
    {
        DB::transaction(function () use ($transfer, $complete, $shipmentManaged) {
            if (!$shipmentManaged && \App\Models\ShipmentItem::where('transfer_id', $transfer->id)->exists()) {
                throw ValidationException::withMessages(['transfer' => 'Ez a tétel szállítmányhoz tartozik. Az átvételt vagy visszavonást a szállítmány oldalán végezd.']);
            }
            // Always lock product before transfer/stock to keep the lock order consistent.
            $this->lockProduct((int) $transfer->product_id);
            $locked = InventoryTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['transfer' => 'Csak úton lévő átmozgatás vehető át vagy vonható vissza.']);
            }
            $warehouseId = $complete ? $locked->to_warehouse_id : $locked->from_warehouse_id;
            $this->changeStock((int) $locked->product_id, (int) $warehouseId, (int) $locked->quantity);
            $locked->update(['status' => $complete ? 'completed' : 'cancelled']);
        }, 3);
    }

    private function lockProduct(int $productId): void
    {
        // A stock row may not exist yet. The product always exists, so its lock also
        // serializes concurrent first receipts and creation of destination stock.
        Product::whereKey($productId)->lockForUpdate()->firstOrFail();
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
        if ($stock) {
            $stock->update(['quantity' => $quantity, 'quarantined_quantity' => $quarantined]);
        } else {
            Stock::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'quarantined_quantity' => $quarantined]);
        }
        return ['stock_before' => $before, 'stock_after' => $quantity];
    }
}
