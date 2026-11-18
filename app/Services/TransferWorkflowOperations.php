<?php

namespace App\Services;

use App\Models\{TransferIntent, TransferStockReservation, TransferWorkflowEvent, User, WarehouseTransferTask};
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Transaction and replay support for the intent's shared append-only event stream. */
class TransferWorkflowOperations
{
    private const INTENT_FIELDS = [
        'id', 'reference', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity',
        'requested_by', 'requested_arrival_at', 'reason', 'note', 'priority', 'shipping_preference',
        'status', 'version', 'created_at', 'updated_at',
    ];

    private const EVENT_FIELDS = [
        'id', 'event_type', 'from_status', 'to_status', 'actor_id', 'occurred_at', 'reason',
    ];

    private const RESERVATION_FIELDS = [
        'id', 'transfer_intent_id', 'stock_id', 'quantity', 'remaining_quantity', 'status',
        'reserved_by', 'reserved_at', 'released_by', 'released_at',
    ];

    private const TASK_FIELDS = [
        'id', 'transfer_intent_id', 'transfer_stock_reservation_id', 'stock_id', 'warehouse_id',
        'type', 'expected_quantity', 'staged_quantity', 'status', 'created_by', 'performed_by',
        'started_at', 'completed_at', 'created_at', 'updated_at',
    ];

    public function run(string $key, string $fingerprint, Closure $operation): array
    {
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A competing operation may claim this key. Tentative changes were rolled back.
            $existing = $this->findEvent($key, true);
            if (!$existing) throw $exception;

            return $this->replay($existing, $fingerprint);
        }
    }

    public function log(
        TransferIntent $intent, User $actor, string $type, ?string $from, ?string $reason, string $key, string $fingerprint,
        ?TransferStockReservation $reservation = null, ?WarehouseTransferTask $task = null, ?array $unassignedConfirmation = null,
    ): array {
        $metadata = [
            'request_hash' => $fingerprint,
            'intent_snapshot' => $this->snapshot($intent->attributesToArray(), self::INTENT_FIELDS),
        ];
        if ($reservation) $metadata['reservation_snapshot'] = $this->reservationSnapshot($reservation);
        if ($task) $metadata['task_snapshot'] = $this->taskSnapshot($task);
        if ($unassignedConfirmation !== null) $metadata['unassigned_confirmation'] = $unassignedConfirmation;

        $event = $intent->events()->create([
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $intent->status,
            'actor_id' => $actor->getKey(),
            'occurred_at' => now(),
            'reason' => $reason,
            'operation_key' => $key,
            'metadata' => $metadata,
        ]);

        return $this->response($event);
    }

    public function replay(TransferWorkflowEvent $event, string $fingerprint): array
    {
        $originalHash = $event->metadata['request_hash'] ?? null;
        if (!is_string($originalHash) || !hash_equals($originalHash, $fingerprint)
            || !is_array($event->metadata['intent_snapshot'] ?? null)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik kéréshez tartozik. Az új művelethez használj új azonosítót.');
        }

        return $this->response($event);
    }

    public function findEvent(string $key, bool $current = false): ?TransferWorkflowEvent
    {
        $query = TransferWorkflowEvent::where('operation_key', $key);
        // Do not lock a missing key during a write path. InnoDB gap locks can block concurrent inserts.
        return ($current ? $query->sharedLock() : $query)->first();
    }

    public function fingerprint(string $type, User $actor, ?int $intentId, array $payload): string
    {
        return hash('sha256', json_encode([$type, (int) $actor->getKey(), $intentId, $payload], JSON_THROW_ON_ERROR));
    }

    public function currentActor(User $actor): User
    {
        $current = User::whereKey($actor->getKey())->sharedLock()->first();
        abort_unless($current, 403, 'A felhasználói fiók már nem érhető el.');

        return $current;
    }

    public function reservationSnapshot(TransferStockReservation $reservation): array
    {
        return $this->snapshot($reservation->attributesToArray(), self::RESERVATION_FIELDS);
    }

    public function taskSnapshot(WarehouseTransferTask $task): array
    {
        return $this->snapshot($task->attributesToArray(), self::TASK_FIELDS) + [
            'dispatched_at' => $task->dispatched_at?->toISOString(),
            'active_staged_quantity' => $task->activeStagedQuantity(), 'dispatched_quantity' => $task->dispatchedQuantity(),
        ];
    }

    private function response(TransferWorkflowEvent $event): array
    {
        $result = [
            'intent' => $this->snapshot($event->metadata['intent_snapshot'], self::INTENT_FIELDS),
            'event' => $this->snapshot($event->attributesToArray(), self::EVENT_FIELDS),
        ];
        if (in_array($event->event_type, ['stock_reserved', 'stock_released', 'picking_task_created', 'stock_picked'], true)) {
            $result['reservation'] = $this->snapshot($event->metadata['reservation_snapshot'], self::RESERVATION_FIELDS);
        }
        if (isset($event->metadata['task_snapshot'])) {
            $result['task'] = $this->snapshot($event->metadata['task_snapshot'], self::TASK_FIELDS);
        }
        if ($confirmation = $event->unassignedConfirmation()) {
            $result['event']['unassigned_confirmation'] = $confirmation;
        }

        return $result;
    }

    private function snapshot(array $attributes, array $fields): array
    {
        // MySQL may reorder object keys in JSON metadata. Return the same field order on replay.
        $template = array_fill_keys($fields, null);

        return array_replace($template, array_intersect_key($attributes, $template));
    }
}
