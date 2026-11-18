<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasOne};

class ShipmentIntentAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_id', 'shipment_stop_id', 'transfer_intent_id', 'assigned_by', 'assigned_at',
        'detached_by', 'detached_at', 'detach_reason'];
    protected $hidden = ['active_intent_id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'detached_at' => 'immutable_datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('detached_at');
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function stop(): BelongsTo { return $this->belongsTo(ShipmentStop::class, 'shipment_stop_id'); }
    public function intent(): BelongsTo { return $this->belongsTo(TransferIntent::class, 'transfer_intent_id'); }
    public function cargo(): HasOne { return $this->hasOne(ShipmentAssignmentCargo::class); }
    public function transitItem(): HasOne { return $this->hasOne(ShipmentTransitItem::class); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function detacher(): BelongsTo { return $this->belongsTo(User::class, 'detached_by'); }
}
