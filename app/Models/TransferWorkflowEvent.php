<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferWorkflowEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'transfer_intent_id', 'event_type', 'from_status', 'to_status', 'actor_id',
        'occurred_at', 'reason', 'metadata', 'operation_key',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(TransferIntent::class, 'transfer_intent_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
