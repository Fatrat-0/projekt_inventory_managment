<?php

namespace App\Policies;

use App\Models\{Shipment, ShipmentReceiptItem, ShipmentStop, User, Warehouse};
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class ShipmentPolicy
{
    private const VIEW_ROLES = ['supervisor', 'dispatch_operator'];
    private const PLAN_ROLES = ['supervisor'];
    private const RECEIVING_ROLES = ['supervisor', 'receiving_operator'];

    public function viewAnyPlan(User $user): Response
    {
        return $user->role === 'admin' || $user->warehouseMemberships()->whereIn('role', self::VIEW_ROLES)->exists()
            ? Response::allow() : Response::deny('A fuvartervek megtekintéséhez forrásraktári jogosultság szükséges.');
    }

    public function visiblePlans(Builder $query, User $user): Builder
    {
        $query->where('planning_mode', Shipment::MODE_TRANSFER_INTENT);
        if ($user->role === 'admin') return $query;

        return $query->whereIn('origin_warehouse_id', $user->warehouseMemberships()
            ->whereIn('role', self::VIEW_ROLES)->select('warehouse_id'));
    }

    public function viewPlan(User $user, Shipment $shipment): Response
    {
        return $this->hasAccess($user, (int) $shipment->origin_warehouse_id, self::VIEW_ROLES)
            ? Response::allow() : Response::deny('Ehhez a fuvartervhez forrásraktári megtekintési jogosultság szükséges.');
    }

    public function createPlan(User $user, int $originId): Response
    {
        return $this->hasAccess($user, $originId, self::PLAN_ROLES)
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
            $query->whereIn('warehouse_id', $user->warehouseMemberships()
                ->whereIn('role', $forCreation ? self::PLAN_ROLES : self::VIEW_ROLES)->select('warehouse_id'));
        }

        return $query->orderBy('warehouse_name')->orderBy('warehouse_id');
    }

    public function managePlan(User $user, Shipment $shipment): Response
    {
        return $this->createPlan($user, (int) $shipment->origin_warehouse_id);
    }

    public function overrideDedicated(User $user, Shipment $shipment): Response
    {
        return $this->managePlan($user, $shipment);
    }

    public function dispatchPlan(User $user, Shipment $shipment): Response
    {
        return $this->hasAccess($user, (int) $shipment->origin_warehouse_id, self::PLAN_ROLES)
            ? Response::allow() : Response::deny('Fuvart a forrásraktár felügyelője vagy központi adminisztrátor adhat fel.');
    }

    public function viewAnyReceiving(User $user): Response
    {
        return $user->role === 'admin' || $this->receivingWarehouseIds($user)->exists()
            ? Response::allow() : Response::deny('A fuvarátvételhez célraktári jogosultság szükséges.');
    }

    public function receivingWarehouseIds(User $user): Builder
    {
        return $user->warehouseMemberships()->getQuery()->whereIn('role', self::RECEIVING_ROLES)->select('warehouse_id');
    }

    public function receiveStop(User $user, ShipmentStop $stop): Response
    {
        return $this->hasAccess($user, (int) $stop->warehouse_id, self::RECEIVING_ROLES)
            ? Response::allow() : Response::deny('Ehhez az átvételhez a megálló célraktárában kell átvételi jogosultsággal rendelkezned.');
    }

    public function putawayReceiptItem(User $user, ShipmentReceiptItem $item): Response
    {
        return $this->hasAccess($user, (int) $item->to_warehouse_id, self::RECEIVING_ROLES)
            ? Response::allow() : Response::deny('A betároláshoz az átvétel célraktárában kell átvételi jogosultsággal rendelkezned.');
    }

    private function hasAccess(User $user, int $warehouseId, array $roles): bool
    {
        return $user->role === 'admin' || $user->warehouseMemberships()->where('warehouse_id', $warehouseId)
            ->whereIn('role', $roles)->sharedLock()->first(['id']) !== null;
    }
}
