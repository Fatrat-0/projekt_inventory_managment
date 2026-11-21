<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseRelocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reference', 'warehouse_id', 'warehouse_name', 'product_id', 'product_name', 'sku',
        'from_location_id', 'from_location_code', 'from_location_name', 'to_location_id', 'to_location_code', 'to_location_name',
        'destination_placement_id', 'quantity', 'cargo_weight_grams', 'pallet_slots', 'dimensions_cm',
        'load_source', 'source_load_verified', 'site_verified', 'geometry_status', 'source_allocations', 'balances',
        'actor_id', 'actor_name', 'relocated_at', 'reason', 'note', 'operation_key', 'request_hash',
    ];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer',
            'dimensions_cm' => 'array', 'source_allocations' => 'array', 'balances' => 'array',
            'source_load_verified' => 'boolean', 'site_verified' => 'boolean', 'relocated_at' => 'immutable_datetime',
        ];
    }
}
