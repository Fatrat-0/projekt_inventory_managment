<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehousePlacement extends Model
{
    protected $fillable = ['warehouse_location_id', 'stock_id', 'quantity', 'cargo_weight_grams', 'pallet_slots', 'load_requires_verification'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer', 'load_requires_verification' => 'boolean'];
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
