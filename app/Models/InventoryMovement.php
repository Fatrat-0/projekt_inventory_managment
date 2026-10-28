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
        'stock_before',
        'stock_after',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'stock_before' => 'integer', 'stock_after' => 'integer'];
    }

    public function getMovementNumberAttribute(): string
    {
        return 'MOZ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

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
