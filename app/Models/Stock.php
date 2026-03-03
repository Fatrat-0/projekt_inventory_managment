<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    // Itt maradt a sima 'id', így nem kell primaryKey-t megadni
    protected $fillable = ['warehouse_id', 'product_id', 'quantity', 'min_quantity'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // A készlethez TÖBB mozgás (naplóbejegyzés) is tartozik
    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'stock_id', 'id');
    }
}
