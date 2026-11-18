<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransferSourceAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_id', 'warehouse_location_id', 'source_placement_id', 'location_code', 'location_name',
        'quantity', 'cargo_weight_grams', 'pallet_slots', 'returned_to_location',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer',
            'returned_to_location' => 'boolean',
        ];
    }

    public function transfer()
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_id');
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
