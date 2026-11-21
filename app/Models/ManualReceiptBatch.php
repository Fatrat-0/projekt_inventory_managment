<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ManualReceiptBatch extends Model
{
    protected $fillable = ['movement_id', 'product_id', 'warehouse_id', 'received_quantity', 'pending_putaway_quantity'];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'integer', 'pending_putaway_quantity' => 'integer',
            'putaway_version' => 'integer', 'closed_at' => 'immutable_datetime',
        ];
    }

    public function movement(): BelongsTo { return $this->belongsTo(InventoryMovement::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function putaways(): HasMany { return $this->hasMany(ManualReceiptPutaway::class); }

    public function state(): array
    {
        $pending = $this->pending_putaway_quantity;
        $placed = $this->received_quantity - $pending;

        return [
            'id' => $this->id, 'movement_id' => (int) $this->movement_id, 'product_id' => (int) $this->product_id,
            'warehouse_id' => (int) $this->warehouse_id, 'received_quantity' => $this->received_quantity,
            'pending_putaway_quantity' => $pending, 'placed_quantity' => $placed, 'version' => $this->putaway_version,
            'status' => $pending === 0 ? 'completed' : ($placed === 0 ? 'pending' : 'partial'),
            'can_putaway' => $pending > 0 && $this->putaway_version < 4294967295,
            'received_at' => $this->created_at->toISOString(), 'closed_at' => $this->closed_at?->toISOString(),
        ];
    }
}
