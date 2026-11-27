<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'changes', 'occurred_at'];

    public const LABELS = [
        'created' => 'Fiók létrehozása',
        'updated' => 'Fiók módosítása',
        'deactivated' => 'Inaktiválás',
        'reactivated' => 'Újraaktiválás',
        'password_link' => 'Jelszóbeállítás kezdeményezése',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
