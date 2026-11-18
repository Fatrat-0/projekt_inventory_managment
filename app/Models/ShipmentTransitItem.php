<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

class ShipmentTransitItem extends Model
{
    public const IN_TRANSIT = 'in_transit';
    public const RECEIVED = 'received';

    protected $fillable = ['shipment_id', 'shipment_dispatch_id', 'transfer_intent_id', 'shipment_intent_assignment_id',
        'warehouse_transfer_task_id', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'shipment_stop_id',
        'dispatched_quantity', 'remaining_quantity', 'status'];

    protected $attributes = ['status' => self::IN_TRANSIT];

    protected function casts(): array
    {
        return ['dispatched_quantity' => 'integer', 'remaining_quantity' => 'integer'];
    }

    public function getReceivedQuantityAttribute(): int
    {
        return $this->dispatched_quantity - $this->remaining_quantity;
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function dispatch(): BelongsTo { return $this->belongsTo(ShipmentDispatch::class, 'shipment_dispatch_id'); }
    public function intent(): BelongsTo { return $this->belongsTo(TransferIntent::class, 'transfer_intent_id'); }
    public function assignment(): BelongsTo { return $this->belongsTo(ShipmentIntentAssignment::class, 'shipment_intent_assignment_id'); }
    public function task(): BelongsTo { return $this->belongsTo(WarehouseTransferTask::class, 'warehouse_transfer_task_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id', 'product_id'); }
    public function fromWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'from_warehouse_id', 'warehouse_id'); }
    public function toWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'to_warehouse_id', 'warehouse_id'); }
    public function stop(): BelongsTo { return $this->belongsTo(ShipmentStop::class, 'shipment_stop_id'); }
    public function movement(): HasOne { return $this->hasOne(InventoryMovement::class); }
    public function receiptItems(): HasMany { return $this->hasMany(ShipmentReceiptItem::class); }
}
