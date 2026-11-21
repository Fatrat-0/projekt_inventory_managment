<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualReceiptPutaway extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'manual_receipt_batch_id', 'warehouse_placement_id', 'warehouse_location_id', 'location_code', 'location_name',
        'product_name', 'sku', 'warehouse_name', 'actor_id', 'actor_name', 'quantity', 'cargo_weight_grams', 'pallet_slots',
        'dimensions_cm', 'geometry_status', 'site_verified', 'weight_source', 'packaging_revision', 'note', 'operation_key',
        'request_hash', 'result', 'placed_at',
    ];

    protected $hidden = ['operation_key', 'request_hash', 'result'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'cargo_weight_grams' => 'integer', 'pallet_slots' => 'integer',
            'dimensions_cm' => 'array', 'site_verified' => 'boolean', 'result' => 'array', 'placed_at' => 'immutable_datetime',
        ];
    }

    public function eventData(): array
    {
        return $this->toArray() + [
            'movement_id' => $this->result['batch']['movement_id'], 'warehouse_id' => $this->result['batch']['warehouse_id'],
            'product_id' => $this->result['batch']['product_id'], 'batch_after' => $this->result['batch'],
        ];
    }
}
