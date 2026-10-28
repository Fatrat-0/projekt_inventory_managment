<?php

namespace App\Services;

use App\Models\{InventoryMovement, InventoryTransfer, Product, Stock};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

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
                $balances = $this->changeStock($payload['product_id'], $payload['warehouse_id'],
                    $payload['type'] === 'in' ? $payload['quantity'] : -$payload['quantity']);
                $movement = InventoryMovement::create(array_merge($payload, $balances));
                if ($requestId !== null) {
                    DB::table('inventory_movement_requests')->where('id', $requestId)
                        ->update(['movement_id' => $movement->id, 'updated_at' => now()]);
                }
                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($requestKey === null) throw $e;
            // The conflicting transaction has committed by the time its unique insert fails.
            // Read outside the rolled-back transaction to see that committed request.
            $previous = DB::table('inventory_movement_requests')->where('user_id', $payload['user_id'])
                ->where('request_key', $requestKey)->first();
            if (!$previous || !$previous->movement_id) throw $e;
            if (!hash_equals($previous->payload_hash, $hash)) {
                throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új művelethez nyiss új készletmozgás-űrlapot.']);
            }
            return InventoryMovement::findOrFail($previous->movement_id);
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

    private function changeStock(int $productId, int $warehouseId, int $delta): array
    {
        $stock = Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->lockForUpdate()->first();
        $before = (int) ($stock?->quantity ?? 0);
        $quantity = $before + $delta;
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'Nincs elegendő készlet a kiválasztott raktárban.']);
        }
        if ($quantity > 2147483647) {
            throw ValidationException::withMessages(['quantity' => 'A készlet meghaladná a megengedett legnagyobb mennyiséget.']);
        }
        if ($stock) {
            $stock->update(['quantity' => $quantity]);
        } else {
            Stock::create(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $quantity]);
        }
        return ['stock_before' => $before, 'stock_after' => $quantity];
    }
}
