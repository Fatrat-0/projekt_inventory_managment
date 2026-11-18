<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentReceiptPutaway extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipment_receipt_item_id', 'warehouse_placement_id', 'warehouse_location_id', 'location_code', 'location_name',
        'actor_id', 'quantity', 'cargo_weight_grams', 'pallet_slots', 'dimensions_cm', 'geometry_status', 'site_verified',
        'weight_source', 'packaging_revision', 'note', 'operation_key', 'request_hash', 'result', 'placed_at',
    ];

    protected $hidden = ['operation_key', 'request_hash', 'result'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cargo_weight_grams' => 'integer',
            'pallet_slots' => 'integer',
            'dimensions_cm' => 'array',
            'site_verified' => 'boolean',
            'result' => 'array',
            'placed_at' => 'immutable_datetime',
        ];
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentReceiptItem::class, 'shipment_receipt_item_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(WarehousePlacement::class, 'warehouse_placement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function eventData(): array
    {
        return [
            'id' => $this->id,
            'placed_at' => $this->placed_at->toISOString(),
            'actor_id' => (int) $this->actor_id,
            'quantity' => $this->quantity,
            'cargo_weight_grams' => $this->cargo_weight_grams,
            'pallet_slots' => $this->pallet_slots,
            'dimensions_cm' => $this->dimensions_cm,
            'geometry_status' => $this->geometry_status,
            'site_verified' => $this->site_verified,
            'weight_source' => $this->weight_source,
            'packaging_revision' => $this->packaging_revision,
            'note' => $this->note,
        ];
    }
}
