<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'user_id',
        'type',
        'quantity',
        'reference',
    ];

    // Kapcsolat a Termékkel
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    // Kapcsolat a Raktárral
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id');
    }

    // Melyik felhasználó csinálta a tranzakciót
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }



}
