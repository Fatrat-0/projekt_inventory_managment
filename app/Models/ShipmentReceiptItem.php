<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

class ShipmentReceiptItem extends Model
{
    public $timestamps = false;

    protected $attributes = ['putaway_version' => 1];

    protected $fillable = ['shipment_receipt_id', 'shipment_transit_item_id', 'shipment_stop_id', 'transfer_intent_id',
        'to_warehouse_id', 'product_id', 'received_quantity', 'pending_putaway_quantity', 'note'];

    protected function casts(): array
    {
        return ['received_quantity' => 'integer', 'pending_putaway_quantity' => 'integer', 'putaway_version' => 'integer'];
    }

    public function receipt(): BelongsTo { return $this->belongsTo(ShipmentReceipt::class, 'shipment_receipt_id'); }
    public function transitItem(): BelongsTo { return $this->belongsTo(ShipmentTransitItem::class, 'shipment_transit_item_id'); }
    public function intent(): BelongsTo { return $this->belongsTo(TransferIntent::class, 'transfer_intent_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id', 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'to_warehouse_id', 'warehouse_id'); }
    public function movement(): HasOne { return $this->hasOne(InventoryMovement::class); }
    public function putaways(): HasMany { return $this->hasMany(ShipmentReceiptPutaway::class); }
}
