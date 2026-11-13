<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseMembership extends Model
{
    private const CAPABILITIES = [
        'requester' => ['request'],
        'dispatch_operator' => ['request', 'pick', 'dispatch'],
        'receiving_operator' => ['request', 'receive', 'putaway'],
        'supervisor' => ['request', 'approve', 'pick', 'dispatch', 'receive', 'putaway', 'supervise'],
    ];

    protected $fillable = ['user_id', 'warehouse_id', 'role'];

    public function allows(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES[$this->role] ?? [], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
