<?php

namespace App\Observers;

use App\Models\InventoryMovement;

class InventoryMovementObserver
{
    /**
     * Handle the InventoryMovement "created" event.
     */
    public function created(InventoryMovement $inventoryMovement): void
    {
        // Megkeressük vagy létrehozzuk a rekordot a stocks táblában
        $stock = \App\Models\Stock::firstOrNew([
            'product_id' => $inventoryMovement->product_id,
            'warehouse_id' => $inventoryMovement->warehouse_id,
        ]);

        // Ha bejövő (IN), hozzáadjuk, ha kimenő (OUT), levonjuk a mennyiséget
        if ($inventoryMovement->type === 'in') {
            $stock->quantity += $inventoryMovement->quantity;
        } else {
            $stock->quantity -= $inventoryMovement->quantity;
        }

        $stock->save();
    }

    /**
     * Handle the InventoryMovement "updated" event.
     */
    public function updated(InventoryMovement $inventoryMovement): void
    {
        //
    }

    /**
     * Handle the InventoryMovement "deleted" event.
     */
    public function deleted(InventoryMovement $inventoryMovement): void
    {
        //
    }

    /**
     * Handle the InventoryMovement "restored" event.
     */
    public function restored(InventoryMovement $inventoryMovement): void
    {
        //
    }

    /**
     * Handle the InventoryMovement "force deleted" event.
     */
    public function forceDeleted(InventoryMovement $inventoryMovement): void
    {
        //
    }
}
