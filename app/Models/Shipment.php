<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};
use Illuminate\Support\Collection;

class Shipment extends Model
{
    public const STATUSES = ['draft' => 'Tervezet', 'in_transit' => 'Úton', 'completed' => 'Teljesítve', 'cancelled' => 'Visszavonva'];
    public const MODE_LEGACY = 'legacy';
    public const MODE_TRANSFER_INTENT = 'transfer_intent';

    protected $fillable = ['origin_warehouse_id', 'user_id', 'reference', 'vehicle_label', 'note', 'status', 'version', 'started_at', 'finished_at',
        'planning_mode', 'planned_departure_at', 'vehicle_id', 'cargo_revision'];
    protected $attributes = ['planning_mode' => self::MODE_LEGACY, 'cargo_revision' => 0];
    protected function casts(): array
    {
        return ['version' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'planned_departure_at' => 'immutable_datetime',
            'vehicle_id' => 'integer', 'cargo_revision' => 'integer'];
    }
    public function origin() { return $this->belongsTo(Warehouse::class, 'origin_warehouse_id', 'warehouse_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function stops() { return $this->hasMany(ShipmentStop::class)->orderBy('position'); }
    public function intentAssignments(): HasMany { return $this->hasMany(ShipmentIntentAssignment::class); }
    public function planOperations(): HasMany { return $this->hasMany(ShipmentPlanOperation::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function cargoConfirmation(): HasOne { return $this->hasOne(ShipmentCargoConfirmation::class); }
    public function dispatch(): HasOne { return $this->hasOne(ShipmentDispatch::class); }
    public function transitItems(): HasMany { return $this->hasMany(ShipmentTransitItem::class)->orderBy('id'); }
    public function receipts(): HasMany { return $this->hasMany(ShipmentReceipt::class); }

    public static function loadingStops(Collection $stops): Collection
    {
        return $stops->sortByDesc('position')->values();
    }

    public function isTransferIntentPlan(): bool { return $this->planning_mode === self::MODE_TRANSFER_INTENT; }
    public function getShipmentNumberAttribute(): string { return self::formatNumber((int) $this->id); }
    public static function formatNumber(int $id): string { return 'SZALL-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
}
