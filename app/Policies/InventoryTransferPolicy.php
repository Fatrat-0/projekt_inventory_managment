<?php

namespace App\Policies;

use App\Models\{InventoryTransfer, User};
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class InventoryTransferPolicy
{
    public function __construct(private ShipmentPolicy $shipments) {}

    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') return $query;

        return $query->where(fn (Builder $allowed) => $allowed
            ->whereIn('from_warehouse_id', $this->shipments->originWarehouseIds($user))
            ->orWhereIn('to_warehouse_id', $this->shipments->receivingWarehouseIds($user)));
    }

    public function view(User $user, InventoryTransfer $transfer): Response
    {
        return $this->visibleTo(InventoryTransfer::whereKey($transfer->id), $user)->exists()
            ? Response::allow() : Response::deny('Ehhez az átmozgatáshoz forrás- vagy célraktári jogosultság szükséges.');
    }

    public function create(User $user): Response
    {
        return $this->shipments->createAnyPlan($user);
    }

    public function start(User $user, int $sourceId): Response
    {
        return $this->shipments->createPlan($user, $sourceId)->allowed()
            ? Response::allow() : Response::deny('Az átmozgatást a forrásraktár felügyelője vagy központi adminisztrátor indíthatja vagy vonhatja vissza.');
    }

    public function viewPickingSources(User $user, int $sourceId): Response
    {
        return $this->shipments->canViewOrigin($user, $sourceId)
            ? Response::allow() : Response::deny('A forráshelyek megtekintéséhez forrásraktári kitárolási vagy felügyelői jogosultság szükséges.');
    }

    public function complete(User $user, InventoryTransfer $transfer): Response
    {
        return $this->shipments->receiveInWarehouse($user, (int) $transfer->to_warehouse_id);
    }

    public function cancel(User $user, InventoryTransfer $transfer): Response
    {
        return $this->start($user, (int) $transfer->from_warehouse_id);
    }
}
