<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentPlanOperation extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_id', 'actor_id', 'operation_key', 'action', 'request_hash', 'reason', 'result', 'occurred_at'];
    protected $hidden = ['operation_key', 'request_hash', 'result'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'result' => 'array'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class); }
}
