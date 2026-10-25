<?php

namespace App\Services;

use App\Models\{InventoryMovement, InventoryTransfer, Product, Stock};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** All application stock writes use one transaction and the same product-row lock. */
class InventoryService
{
    public function recordMovement(array $data): InventoryMovement
    {
        return DB::transaction(function () use ($data) {
            $this->lockProduct((int) $data['product_id']);
            $this->changeStock((int) $data['product_id'], (int) $data['warehouse_id'],
                $data['type'] === 'in' ? (int) $data['quantity'] : -(int) $data['quantity']);

            return InventoryMovement::create($data);
        }, 3);
    }

    public function startTransfer(array $data): InventoryTransfer
    {
        return DB::transaction(function () use ($data) {
            $this->lockProduct((int) $data['product_id']);
            $this->changeStock((int) $data['product_id'], (int) $data['from_warehouse_id'], -(int) $data['quantity']);

            return InventoryTransfer::create(array_merge($data, ['status' => 'pending']));
        }, 3);
    }

    public function finalizeTransfer(InventoryTransfer $transfer, bool $complete): void
    {
        DB::transaction(function () use ($transfer, $complete) {
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

    private function changeStock(int $productId, int $warehouseId, int $delta): void
    {
        $stock = Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->lockForUpdate()->first();
        $quantity = (int) ($stock?->quantity ?? 0) + $delta;
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
    }
}
