<?php

namespace App\Policies;

use App\Models\{TransferIntent, User};
use App\Services\TransferIntentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class TransferIntentPolicy
{
    private const SOURCE_VIEW_ROLES = ['dispatch_operator', 'supervisor'];
    private const DESTINATION_VIEW_ROLES = ['receiving_operator', 'supervisor'];

    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($this->isCentralAdmin($user)) return $query;

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('requested_by', $user->getKey())
                ->orWhereHas('fromWarehouse.memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())->whereIn('role', self::SOURCE_VIEW_ROLES))
                ->orWhereHas('toWarehouse.memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())->whereIn('role', self::DESTINATION_VIEW_ROLES));
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
        $mayRequest = $this->isCentralAdmin($user) || $user->warehouseMemberships()
            ->whereIn('warehouse_id', [$fromWarehouseId, $toWarehouseId])
            ->orderBy('warehouse_id')->sharedLock()
            ->get(['role'])->contains(fn ($membership) => $membership->allows('request'));

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

    public function cancel(User $user, TransferIntent $intent): Response
    {
        return (int) $intent->requested_by === (int) $user->getKey() || $this->mayDecide($user, $intent)
            ? Response::allow()
            : Response::deny('Az igényt a kezdeményező, a forrásraktár felügyelője vagy központi adminisztrátor vonhatja vissza.');
    }

    public function requestWarehouseIds(User $user): array
    {
        return $user->warehouseMemberships()->get(['warehouse_id', 'role'])
            ->filter(fn ($membership) => $membership->allows('request'))
            ->pluck('warehouse_id')->map(fn ($id) => (string) $id)->values()->all();
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
        return $this->isCentralAdmin($user) || $user->warehouseMemberships()
            ->where('warehouse_id', $intent->from_warehouse_id)->where('role', 'supervisor')
            ->sharedLock()->first(['id']) !== null;
    }

    private function isCentralAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }
}
