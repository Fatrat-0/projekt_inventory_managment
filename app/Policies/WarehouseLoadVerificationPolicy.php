<?php

namespace App\Policies;

use App\Models\User;

class WarehouseLoadVerificationPolicy
{
    public function __construct(private WarehouseRelocationPolicy $warehouseAccess) {}

    public function read(User $actor, int $warehouseId): void
    {
        abort_unless($this->warehouseAccess->canAccessWarehouse($actor, $warehouseId), 403,
            'A raktár terhelésellenőrzési adataihoz operátori vagy felügyelői jogosultság szükséges.');
    }

    public function canVerify(User $actor, int $warehouseId, bool $locked = false): bool
    {
        return $actor->role === 'admin' || $actor->warehouseMemberships()->where('warehouse_id', $warehouseId)
            ->where('role', 'supervisor')->when($locked, fn ($query) => $query->sharedLock())->first(['id']) !== null;
    }

    public function verify(User $actor, int $warehouseId, bool $locked = false): void
    {
        abort_unless($this->canVerify($actor, $warehouseId, $locked), 403,
            'Terhelésellenőrzést a raktár felügyelője vagy egy adminisztrátor rögzíthet.');
    }
}
