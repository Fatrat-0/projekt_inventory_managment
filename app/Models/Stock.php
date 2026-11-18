<?php

namespace App\Models;

use App\Services\WarehouseStockAvailability;
use Illuminate\Database\Eloquent\{Builder, Model};

class Stock extends Model
{
    // Itt maradt a sima 'id', így nem kell primaryKey-t megadni
    protected $fillable = ['warehouse_id', 'product_id', 'quantity', 'min_quantity', 'quarantined_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quarantined_quantity' => 'integer', 'min_quantity' => 'integer'];
    }

    public function scopeWithPendingPutaway(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) $query->select('stocks.*');

        return $query->selectSub(WarehouseStockAvailability::pendingPutawayQuantityForStock(), 'pending_putaway_quantity');
    }

    public function getAvailableQuantityAttribute(): int
    {
        return (int) $this->quantity - (int) $this->quarantined_quantity
            - (int) ($this->attributes['pending_putaway_quantity'] ?? 0);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    public function placements()
    {
        return $this->hasMany(WarehousePlacement::class);
    }

    // A készlethez TÖBB mozgás (naplóbejegyzés) is tartozik
    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'stock_id', 'id');
    }
}
