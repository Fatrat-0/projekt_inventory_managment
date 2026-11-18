<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ShipmentDispatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_id', 'actor_id', 'vehicle_id', 'dispatched_at', 'note', 'snapshot'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'immutable_datetime', 'snapshot' => 'array'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function transitItems(): HasMany { return $this->hasMany(ShipmentTransitItem::class)->orderBy('id'); }
}
