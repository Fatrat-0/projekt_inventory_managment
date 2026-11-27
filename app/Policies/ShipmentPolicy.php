<?php

namespace App\Policies;

use App\Models\{Shipment, ShipmentReceiptItem, ShipmentStop, User, Warehouse};
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class ShipmentPolicy
{
    public function viewAnyPlan(User $user): Response
    {
        return $user->role === 'admin' || $this->originWarehouseIds($user)->exists()
            ? Response::allow() : Response::deny('A fuvartervek megtekintéséhez forrásraktári jogosultság szükséges.');
    }

    public function visiblePlans(Builder $query, User $user): Builder
    {
        $query->where('planning_mode', Shipment::MODE_TRANSFER_INTENT);
        if ($user->role === 'admin') return $query;

        return $query->whereIn('origin_warehouse_id', $this->originWarehouseIds($user));
    }

    public function viewPlan(User $user, Shipment $shipment): Response
    {
        return $this->canViewOrigin($user, (int) $shipment->origin_warehouse_id)
            ? Response::allow() : Response::deny('Ehhez a fuvartervhez forrásraktári megtekintési jogosultság szükséges.');
    }

    public function canViewOrigin(User $user, int $warehouseId): bool
    {
        return $this->hasAccess($user, $warehouseId, 'pick');
    }

    public function createPlan(User $user, int $originId): Response
    {
        return $this->hasAccess($user, $originId, 'supervise')
            ? Response::allow() : Response::deny('Fuvartervet a forrásraktár felügyelője vagy központi adminisztrátor kezelhet.');
    }

    public function createAnyPlan(User $user): Response
    {
        return $user->role === 'admin' || $this->warehousesFor($user, true)->exists()
            ? Response::allow() : Response::deny('Új fuvarhoz forrásraktári felügyelői jogosultság szükséges.');
    }

    public function warehousesFor(User $user, bool $forCreation = false): Builder
    {
        $query = Warehouse::query();
        if ($user->role !== 'admin') {
            $query->whereIn('warehouse_id', $this->originWarehouseIds($user, $forCreation));
        }

        return $query->orderBy('warehouse_name')->orderBy('warehouse_id');
    }

    public function originWarehouseIds(User $user, bool $forCreation = false): Builder
    {
        return $user->warehouseMemberships()->getQuery()
            ->withCapability($forCreation ? 'supervise' : 'pick')->select('warehouse_id');
    }

    public function managePlan(User $user, Shipment $shipment): Response
    {
        return $this->createPlan($user, (int) $shipment->origin_warehouse_id);
    }

    public function legacyReceivingStops(User $user, Shipment $shipment): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $shipment->stops()->whereIn('warehouse_id', $this->receivingWarehouseIds($user));
    }

    public function overrideDedicated(User $user, Shipment $shipment): Response
    {
        return $this->managePlan($user, $shipment);
    }

    public function dispatchPlan(User $user, Shipment $shipment): Response
    {
        return $this->hasAccess($user, (int) $shipment->origin_warehouse_id, 'supervise')
            ? Response::allow() : Response::deny('Fuvart a forrásraktár felügyelője vagy központi adminisztrátor adhat fel.');
    }

    public function viewAnyReceiving(User $user): Response
    {
        return $user->role === 'admin' || $this->receivingWarehouseIds($user)->exists()
            ? Response::allow() : Response::deny('A fuvarátvételhez célraktári jogosultság szükséges.');
    }

    public function receivingWarehouseIds(User $user): Builder
    {
        return $user->warehouseMemberships()->getQuery()->withCapability('receive')->select('warehouse_id');
    }

    public function receiveStop(User $user, ShipmentStop $stop): Response
    {
        return $this->receiveInWarehouse($user, (int) $stop->warehouse_id);
    }

    public function receiveInWarehouse(User $user, int $warehouseId): Response
    {
        return $this->hasAccess($user, $warehouseId, 'receive')
            ? Response::allow() : Response::deny('Ehhez az átvételhez a megálló célraktárában kell átvételi jogosultsággal rendelkezned.');
    }

    public function putawayReceiptItem(User $user, ShipmentReceiptItem $item): Response
    {
        return $this->putawayInWarehouse($user, (int) $item->to_warehouse_id);
    }

    public function putawayInWarehouse(User $user, int $warehouseId): Response
    {
        return $this->hasAccess($user, $warehouseId, 'receive')
            ? Response::allow() : Response::deny('A betároláshoz az átvétel célraktárában kell átvételi jogosultsággal rendelkezned.');
    }

    private function hasAccess(User $user, int $warehouseId, string $capability): bool
    {
        return $user->role === 'admin' || $user->hasWarehouseCapability($capability, $warehouseId);
    }
}
