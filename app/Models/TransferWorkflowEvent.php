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

    /** @return array{found: true, description: string}|null */
    public function unassignedConfirmation(): ?array
    {
        $confirmation = $this->metadata['unassigned_confirmation'] ?? null;
        if ($this->event_type !== 'stock_picked' || !is_array($confirmation) || ($confirmation['found'] ?? null) !== true
            || !is_string($confirmation['description'] ?? null)) {
            return null;
        }

        return ['found' => true, 'description' => $confirmation['description']];
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
