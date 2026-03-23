<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'quantity',
        'status',
        'user_id',
    ];

    // Kapcsolat a Termékkel
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Kapcsolat az Indító (Forrás) Raktárral
    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id', 'warehouse_id');
    }

    // Kapcsolat a Fogadó (Cél) Raktárral
    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id', 'warehouse_id');
    }

    // Kapcsolat a Felhasználóval
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}