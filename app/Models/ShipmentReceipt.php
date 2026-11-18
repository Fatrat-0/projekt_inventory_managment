<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ShipmentReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_id', 'shipment_stop_id', 'to_warehouse_id', 'actor_id', 'received_at',
        'note', 'operation_key', 'request_hash', 'result'];

    protected $hidden = ['operation_key', 'request_hash', 'result'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'result' => 'array'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function stop(): BelongsTo { return $this->belongsTo(ShipmentStop::class, 'shipment_stop_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'to_warehouse_id', 'warehouse_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
    public function items(): HasMany { return $this->hasMany(ShipmentReceiptItem::class)->orderBy('id'); }
}
