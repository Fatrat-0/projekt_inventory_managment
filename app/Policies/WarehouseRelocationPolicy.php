<?php

namespace App\Policies;

use App\Models\{User, Warehouse};
use Illuminate\Database\Eloquent\Builder;

class WarehouseRelocationPolicy
{
    public function warehouses(User $actor): Builder
    {
        $query = Warehouse::query();
        if ($actor->role !== 'admin') {
            $query->whereIn('warehouse_id', $actor->warehouseMemberships()->withCapability('operate')->select('warehouse_id'));
        }

        return $query;
    }

    public function canAccessWarehouse(User $actor, int $warehouseId): bool
    {
        return $actor->role === 'admin' || $this->warehouses($actor)->whereKey($warehouseId)->exists();
    }

    public function authorize(User $actor, int $warehouseId, bool $locked = false): void
    {
        $allowed = $actor->role === 'admin' || $actor->hasWarehouseCapability('operate', $warehouseId, $locked);
        abort_unless($allowed, 403, 'Ebben a raktárban az áthelyezéshez operátori vagy felügyelői jogosultság szükséges.');
    }
}
