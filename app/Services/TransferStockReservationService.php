<?php

namespace App\Services;

use App\Models\{Product, Stock, TransferIntent, TransferStockReservation, User, WarehouseTransferTask};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransferStockReservationService
{
    public function __construct(
        private WarehouseStockAvailability $availability,
        private WarehouseCapacity $capacity,
        private TransferWorkflowOperations $operations,
    ) {
    }

    public function reserve(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->change($actor, $intent, $data, true);
    }

    public function release(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->change($actor, $intent, $data, false);
    }

    public function status(User $actor, TransferIntent $intent): array
    {
        Gate::forUser($actor)->authorize('view', $intent);
        // Capture the version and the latest reservation in one statement.
        $row = TransferIntent::query()->whereKey($intent->getKey())
            ->leftJoin('transfer_stock_reservations as reservations', 'reservations.transfer_intent_id', '=', 'transfer_intents.id')
            ->select('reservations.*', 'transfer_intents.id as current_intent_id', 'transfer_intents.version as current_version')
            ->orderByRaw('CASE WHEN reservations.status = ? THEN 0 ELSE 1 END', [TransferStockReservation::ACTIVE])
            ->orderByDesc('reservations.id')->toBase()->first();
        abort_unless($row, 404, 'Az átmozgatási igény nem található.');
        $reservation = $row->id === null ? null : (new TransferStockReservation)->newFromBuilder($row);

        return [
            'transfer_intent_id' => (int) $row->current_intent_id,
            'version' => (int) $row->current_version,
            'status' => $reservation?->status ?? 'unreserved',
            'reservation' => $reservation ? $this->operations->reservationSnapshot($reservation) : null,
        ];
    }

    /** Called only by cancellation while holding the locked intent in its transaction. */
    public function releaseForCancellation(TransferIntent $intent, User $actor): ?TransferStockReservation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Az igény visszavonásához aktív tranzakció szükséges.');
        }

        $this->lockSource($intent);
        $this->cancelUnstartedPickingTask($intent);
        $reservation = $this->activeForUpdate($intent);

        return $reservation ? $this->releaseReservation($reservation, $actor) : null;
    }

    private function change(User $actor, TransferIntent $intent, array $data, bool $reserve): array
    {
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
            if ($data['reason'] === '') $data['reason'] = null;
        }
        $data = Validator::make(
            $data, TransferIntentService::decisionRules(false), TransferIntentService::decisionMessages(),
        )->validate();
        $key = strtolower($data['operation_key']);
        $version = (int) $data['version'];
        $reason = $data['reason'] ?? null;
        $intentId = (int) $intent->getKey();
        $type = $reserve ? 'stock_reserved' : 'stock_released';
        $fingerprint = $this->operations->fingerprint($type, $actor, $intentId, ['version' => $version, 'reason' => $reason]);

        return $this->operations->run($key, $fingerprint, function () use ($actor, $intentId, $reserve, $type, $key, $version, $reason, $fingerprint) {
            $actor = $this->operations->currentActor($actor);
            $current = TransferIntent::whereKey($intentId)->lockForUpdate()->first();
            abort_unless($current, 404, 'Az átmozgatási igény nem található.');
            Gate::forUser($actor)->authorize($reserve ? 'reserveStock' : 'releaseStock', $current);

            $stale = $current->version !== $version;
            $approved = $current->status === 'approved';
            if ($existing = $this->operations->findEvent($key, $stale || !$approved)) {
                return $this->operations->replay($existing, $fingerprint);
            }
            if ($stale) {
                throw new ConflictHttpException('Az igény közben megváltozott. Töltsd be újra az aktuális állapotot.');
            }
            if (!$approved) {
                throw new ConflictHttpException('A foglalás csak jóváhagyott igényhez kezelhető.');
            }
            if ($version === 4294967295) {
                throw new ConflictHttpException('Az igény elérte a megengedett legnagyobb verziószámot.');
            }

            $this->lockSource($current);
            if ($current->pickingTasks()->where('active_intent_id', $current->id)->whereNotNull('dispatched_at')->lockForUpdate()->first(['id'])) {
                throw new ConflictHttpException('Az igény áruját már feladták, ezért a forrásfoglalása nem módosítható.');
            }
            if (!$reserve) $this->cancelUnstartedPickingTask($current);
            $reservation = $this->activeForUpdate($current);
            $reservation = $reserve
                ? $this->reserveStock($current, $actor, $reservation)
                : $this->releaseStock($reservation, $actor);

            $current->forceFill(['version' => $version + 1])->save();

            return $this->operations->log($current, $actor, $type, $current->status, $reason, $key, $fingerprint, $reservation);
        });
    }

    private function lockSource(TransferIntent $intent): void
    {
        // Inventory writes already serialize on the product, including when stock does not exist yet.
        Product::whereKey($intent->product_id)->lockForUpdate()->firstOrFail(['product_id']);
        $this->capacity->lockWarehouses([$intent->from_warehouse_id]);
        Stock::where('warehouse_id', $intent->from_warehouse_id)
            ->where('product_id', $intent->product_id)->lockForUpdate()->first(['id']);
    }

    private function activeForUpdate(TransferIntent $intent): ?TransferStockReservation
    {
        return $intent->stockReservations()->where('active_intent_id', $intent->getKey())->lockForUpdate()->first();
    }

    private function cancelUnstartedPickingTask(TransferIntent $intent): void
    {
        $task = $intent->pickingTasks()->where('active_intent_id', $intent->getKey())->lockForUpdate()->first();
        if (!$task) return;

        if ($task->staged_quantity > 0) {
            throw new ConflictHttpException('Az igényhez már összekészített készlet tartozik. Előbb rögzíteni kell az áru visszahelyezését.');
        }
        $task->update(['status' => WarehouseTransferTask::CANCELLED]);
    }

    private function reserveStock(TransferIntent $intent, User $actor, ?TransferStockReservation $active): TransferStockReservation
    {
        if ($active) {
            throw new ConflictHttpException('Az igényhez már tartozik aktív készletfoglalás.');
        }

        $stock = $this->availability->snapshotForUpdate((int) $intent->from_warehouse_id, (int) $intent->product_id);
        if (!$stock['consistent']) {
            throw ValidationException::withMessages(['quantity' => 'A forrásraktár készletadatai ellentmondásosak. A foglalás előtt ellenőrizd a készletet és a tárhelyeket.']);
        }
        $pickable = $stock['pickable_quantity'];
        if ($intent->quantity > $pickable) {
            throw ValidationException::withMessages(['quantity' => sprintf(
                'Nincs elegendő szabad, kitárolható készlet. Jelenleg %s db foglalható a forrásraktárban.',
                number_format($pickable, 0, ',', ' '),
            )]);
        }

        return $intent->stockReservations()->create([
            'stock_id' => $stock['stock_id'],
            'quantity' => $intent->quantity,
            'remaining_quantity' => $intent->quantity,
            'status' => TransferStockReservation::ACTIVE,
            'reserved_by' => $actor->getKey(),
            'reserved_at' => now(),
        ]);
    }

    private function releaseStock(?TransferStockReservation $active, User $actor): TransferStockReservation
    {
        if (!$active) {
            throw new ConflictHttpException('Az igényhez nincs feloldható aktív készletfoglalás.');
        }

        return $this->releaseReservation($active, $actor);
    }

    private function releaseReservation(TransferStockReservation $reservation, User $actor): TransferStockReservation
    {
        $reservation->update([
            'remaining_quantity' => 0,
            'status' => TransferStockReservation::RELEASED,
            'released_by' => $actor->getKey(),
            'released_at' => now(),
        ]);

        return $reservation;
    }
}
