<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferIntent extends Model
{
    public const MAX_QUANTITY = 2147483647;

    protected $fillable = [
        'product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'requested_by',
        'requested_arrival_at', 'requested_arrival_date', 'reason', 'note', 'priority', 'shipping_preference',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'shipping_preference' => 'groupable',
        'status' => 'pending_approval',
        'version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $intent): void {
            $intent->reference ??= 'TR-'.Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'version' => 'integer', 'requested_arrival_at' => 'immutable_datetime'];
    }

    protected function quantity(): Attribute
    {
        return Attribute::make(set: function (mixed $value): int {
            $quantity = match (true) {
                is_int($value) => $value,
                is_string($value) && ctype_digit($value) => filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT),
                default => false,
            };
            if ($quantity === false || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw ValidationException::withMessages([
                    'quantity' => 'A kért mennyiség 1 és 2 147 483 647 közötti egész darabszám legyen.',
                ]);
            }

            return $quantity;
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransferWorkflowEvent::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(TransferStockReservation::class);
    }

    public function pickingTasks(): HasMany
    {
        return $this->hasMany(WarehouseTransferTask::class)->where('type', WarehouseTransferTask::TYPE_PICKING);
    }

    public function shipmentAssignments(): HasMany
    {
        return $this->hasMany(ShipmentIntentAssignment::class);
    }

    public function transitItem(): HasOne
    {
        return $this->hasOne(ShipmentTransitItem::class);
    }
}
