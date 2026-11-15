<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class WarehouseTransferTask extends Model
{
    public const TYPE_PICKING = 'picking';
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const PICKED = 'picked';
    public const CANCELLED = 'cancelled';
    public const STAGED_STATUSES = [self::IN_PROGRESS, self::PICKED];

    protected $fillable = [
        'transfer_intent_id', 'transfer_stock_reservation_id', 'stock_id', 'warehouse_id', 'type',
        'expected_quantity', 'staged_quantity', 'status', 'created_by', 'performed_by', 'started_at', 'completed_at',
    ];

    protected $attributes = ['type' => self::TYPE_PICKING, 'staged_quantity' => 0, 'status' => self::PENDING];

    protected $hidden = ['active_intent_id'];

    protected function casts(): array
    {
        return [
            'transfer_intent_id' => 'integer', 'transfer_stock_reservation_id' => 'integer',
            'stock_id' => 'integer', 'warehouse_id' => 'integer',
            'expected_quantity' => 'integer', 'staged_quantity' => 'integer',
            'created_by' => 'integer', 'performed_by' => 'integer',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(TransferIntent::class, 'transfer_intent_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(TransferStockReservation::class, 'transfer_stock_reservation_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(WarehouseTransferAllocation::class);
    }
}
