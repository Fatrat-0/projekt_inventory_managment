<?php

namespace App\Policies;

use App\Models\{TransferIntent, User, Warehouse, WarehouseTransferTask};
use App\Services\TransferIntentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class TransferIntentPolicy
{
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($this->isCentralAdmin($user)) return $query;

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('requested_by', $user->getKey())
                ->orWhereHas('fromWarehouse.memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())->withCapability('pick'))
                ->orWhereHas('toWarehouse.memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())->withCapability('receive'));
        });
    }

    public function viewAny(User $user): bool
    {
        // The list always uses visibleTo(), including for users without memberships.
        return true;
    }

    public function view(User $user, TransferIntent $intent): Response
    {
        return $this->visibleTo(TransferIntent::query()->whereKey($intent->getKey()), $user)->exists()
            ? Response::allow()
            : Response::deny('Nincs jogosultságod az átmozgatási igény megtekintéséhez.');
    }

    public function create(User $user, int $fromWarehouseId, int $toWarehouseId): Response
    {
        $mayRequest = $this->isCentralAdmin($user) || $user->hasWarehouseCapability('request', [$fromWarehouseId, $toWarehouseId]);

        return $mayRequest ? Response::allow()
            : Response::deny('Az igény benyújtásához a forrás- vagy a célraktárban igénylési jogosultság szükséges.');
    }

    public function approve(User $user, TransferIntent $intent): Response
    {
        return $this->mayDecide($user, $intent) ? Response::allow()
            : Response::deny('Az igényről a forrásraktár felügyelője vagy központi adminisztrátor dönthet.');
    }

    public function reject(User $user, TransferIntent $intent): Response
    {
        return $this->approve($user, $intent);
    }

    public function reserveStock(User $user, TransferIntent $intent): Response
    {
        return $this->mayDecide($user, $intent) ? Response::allow()
            : Response::deny('Készletet a forrásraktár felügyelője vagy központi adminisztrátor foglalhat és oldhat fel.');
    }

    public function releaseStock(User $user, TransferIntent $intent): Response
    {
        return $this->reserveStock($user, $intent);
    }

    public function createPickingTask(User $user, TransferIntent $intent): Response
    {
        return $this->mayDecide($user, $intent) ? Response::allow()
            : Response::deny('Kitárolási feladatot a forrásraktár felügyelője vagy központi adminisztrátor hozhat létre.');
    }

    public function viewPickingTask(User $user, TransferIntent $intent): Response
    {
        return $this->mayPick($user, $intent) ? Response::allow()
            : Response::deny('A kitárolási feladat megtekintéséhez a forrásraktárban kitárolási jogosultság szükséges.');
    }

    public function viewPickingTaskList(User $user): Response
    {
        return $this->isCentralAdmin($user) || $this->pickingWarehouses(Warehouse::query(), $user)->exists()
            ? Response::allow()
            : Response::deny('A raktári feladatok megtekintéséhez kitárolási jogosultság szükséges.');
    }

    public function pickingWarehouses(Builder $query, User $user): Builder
    {
        return $this->isCentralAdmin($user) ? $query : $query->whereHas('memberships', fn (Builder $membership) => $membership
            ->where('user_id', $user->getKey())->withCapability('pick'));
    }

    public function authorizeManualIssue(User $actor, int $warehouseId): void
    {
        $allowed = $this->isCentralAdmin($actor) || $actor->hasWarehouseCapability('pick', $warehouseId);
        abort_unless($allowed, 403, 'Készletkiadáshoz a forrásraktárban kitárolói vagy felügyelői jogosultság szükséges.');
    }

    public function visiblePickingTasks(Builder $query, User $user): Builder
    {
        return $query->where('type', WarehouseTransferTask::TYPE_PICKING)
            ->whereHas('intent.fromWarehouse', fn (Builder $warehouse) => $this->pickingWarehouses($warehouse, $user));
    }

    public function executePickingTask(User $user, TransferIntent $intent, bool $locked = true): Response
    {
        return $this->mayPick($user, $intent, $locked) ? Response::allow()
            : Response::deny('A kitárolás rögzítéséhez a forrásraktárban kitárolási jogosultság szükséges.');
    }

    public function cancel(User $user, TransferIntent $intent): Response
    {
        return (int) $intent->requested_by === (int) $user->getKey() || $this->mayDecide($user, $intent)
            ? Response::allow()
            : Response::deny('Az igényt a kezdeményező, a forrásraktár felügyelője vagy központi adminisztrátor vonhatja vissza.');
    }

    public function requestWarehouseIds(User $user): array
    {
        return $user->warehouseMemberships()->withCapability('request')
            ->pluck('warehouse_id')->map(fn ($id) => (string) $id)->all();
    }

    /** Actions for the current record state. Write authorization still runs under lock. */
    public function availableActions(User $user, TransferIntent $intent): array
    {
        $mayDecide = $this->mayDecide($user, $intent);
        $actions = $mayDecide && TransferIntentService::allowsTransition('approve', $intent->status)
            ? ['approve', 'reject'] : [];
        $isOwner = (int) $intent->requested_by === (int) $user->getKey();
        if (TransferIntentService::allowsTransition('cancel', $intent->status)
            && ($mayDecide || ($isOwner && $intent->status === 'pending_approval'))) {
            $actions[] = 'cancel';
        }

        return $actions;
    }

    private function mayDecide(User $user, TransferIntent $intent): bool
    {
        return $this->isCentralAdmin($user) || $user->hasWarehouseCapability('supervise', $intent->from_warehouse_id);
    }

    private function mayPick(User $user, TransferIntent $intent, bool $locked = true): bool
    {
        return $this->isCentralAdmin($user) || $user->hasWarehouseCapability('pick', $intent->from_warehouse_id, $locked);
    }

    private function isCentralAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }
}
