<?php

namespace App\Services;

use App\Models\{TransferIntent, User, WarehouseTransferTask};
use App\Policies\TransferIntentPolicy;
use Illuminate\Support\Facades\{DB, Gate};

class WarehouseOpenTaskCounts
{
    public function __construct(
        private TransferIntentPolicy $picking,
        private ShipmentReceivingReader $receiving,
    ) {}

    /** @return array<string, int> */
    public function forUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $counts = [];
            if (Gate::forUser($user)->allows('viewPickingTaskList', TransferIntent::class)) {
                $counts['picking'] = $this->picking->visiblePickingTasks(WarehouseTransferTask::query(), $user)
                    ->whereIn('status', WarehouseTransferTask::OPEN_STATUSES)->count();
            }

            return $counts + $this->receiving->openTaskCounts($user);
        });
    }
}
