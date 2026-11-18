<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentStop extends Model
{
    protected $fillable = ['warehouse_id', 'position', 'received_by', 'received_at', 'receiving_version'];
    protected function casts(): array { return ['position' => 'integer', 'received_at' => 'datetime', 'receiving_version' => 'integer']; }
    public function shipment() { return $this->belongsTo(Shipment::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id', 'warehouse_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function items() { return $this->hasMany(ShipmentItem::class)->orderBy('id'); }
    public function intentAssignments(): HasMany { return $this->hasMany(ShipmentIntentAssignment::class); }
    public function receipts(): HasMany { return $this->hasMany(ShipmentReceipt::class); }
    public function receiptItems(): HasMany { return $this->hasMany(ShipmentReceiptItem::class); }
    public function transitItems(): HasMany { return $this->hasMany(ShipmentTransitItem::class); }
}
