<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferStockReservation extends Model
{
    public const ACTIVE = 'active';
    public const RELEASED = 'released';
    public const CONSUMED = 'consumed';

    public $timestamps = false;

    protected $fillable = [
        'transfer_intent_id', 'stock_id', 'quantity', 'remaining_quantity', 'status',
        'reserved_by', 'reserved_at', 'released_by', 'released_at',
    ];

    protected $hidden = ['active_intent_id'];

    protected function casts(): array
    {
        return [
            'transfer_intent_id' => 'integer', 'stock_id' => 'integer',
            'quantity' => 'integer', 'remaining_quantity' => 'integer',
            'reserved_by' => 'integer', 'released_by' => 'integer',
            'reserved_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(TransferIntent::class, 'transfer_intent_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
