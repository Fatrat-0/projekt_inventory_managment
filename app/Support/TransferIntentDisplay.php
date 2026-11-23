<?php

namespace App\Support;

use App\Models\{TransferIntent, TransferStockReservation, User};
use App\Policies\TransferIntentPolicy;
use App\Services\WarehouseStockAvailability;
use Illuminate\Support\Facades\Gate;

class TransferIntentDisplay
{
    public const STATUS = [
        'pending_approval' => 'Jóváhagyásra vár',
        'approved' => 'Jóváhagyva',
        'rejected' => 'Elutasítva',
        'cancelled' => 'Visszavonva',
    ];

    public const STATUS_STYLE = [
        'pending_approval' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'approved' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'rejected' => 'bg-red-50 text-red-800 ring-red-200',
        'cancelled' => 'bg-gray-100 text-gray-700 ring-gray-200',
    ];

    public const PRIORITY = ['normal' => 'Normál', 'urgent' => 'Sürgős', 'critical' => 'Kritikus'];
    public const SHIPPING = ['groupable' => 'Összevonható', 'dedicated_requested' => 'Külön fuvar kérése'];
    public const EVENTS = [
        'created' => 'Igény benyújtva',
        'approved' => 'Igény jóváhagyva',
        'stock_reserved' => 'Forráskészlet biztosítva',
        'stock_released' => 'Forrásfoglalás feloldva',
        'picking_task_created' => 'Kitárolási feladat létrehozva',
        'stock_picked' => 'Kitárolás rögzítve',
        'rejected' => 'Igény elutasítva',
        'cancelled' => 'Igény visszavonva',
    ];

    public static function requestedArrival(TransferIntent $intent, bool $compact = false): ?string
    {
        $format = $compact ? 'Y.m.d.' : 'Y. m. d.';
        if ($intent->requested_arrival_date !== null) {
            return \Carbon\CarbonImmutable::parse($intent->requested_arrival_date, PlanningTime::ZONE)->format($format);
        }

        return $intent->requested_arrival_at?->timezone(PlanningTime::ZONE)->format($format.' H:i');
    }

    public static function workflow(TransferIntent $intent, User $user, TransferIntentPolicy $policy): array
    {
        $intent->load(['events' => fn ($events) => $events
            ->select(['id', 'transfer_intent_id', 'event_type', 'from_status', 'to_status', 'actor_id', 'occurred_at', 'reason'])
            ->with('actor:id,name')->orderBy('occurred_at')->orderBy('id')]);

        $preparation = $intent->status === 'approved' ? self::preparation($intent, $user) : null;
        $actions = $policy->availableActions($user, $intent);
        $blockedCancel = ($preparation['staged_quantity'] ?? 0) > 0
            || (in_array('cancel', $actions, true) && $intent->shipmentAssignments()->where('active_intent_id', $intent->id)->exists());
        if ($blockedCancel) {
            $actions = array_values(array_diff($actions, ['cancel']));
        }

        return [
            'status' => $intent->status,
            'version' => $intent->version,
            'actions' => $actions,
            'preparation' => $preparation,
            'events' => $intent->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'actor_name' => $event->actor->name,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'reason' => $event->reason,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
            ])->all(),
        ];
    }

    private static function preparation(TransferIntent $intent, User $user): array
    {
        $reservation = $intent->stockReservations()->latest('id')->first(['status', 'quantity', 'remaining_quantity']);
        $task = $intent->pickingTasks()->where('active_intent_id', $intent->id)->first(['status', 'staged_quantity', 'dispatched_at']);
        $reservationStatus = $reservation?->status ?? 'unreserved';
        $active = $reservationStatus === TransferStockReservation::ACTIVE;
        $unreserved = in_array($reservationStatus, ['unreserved', TransferStockReservation::RELEASED], true);
        $staged = $task?->staged_quantity ?? 0;
        $gate = Gate::forUser($user);
        $actions = [];
        $available = null;

        if ($unreserved && !$task && $gate->allows('reserveStock', $intent)) {
            $actions[] = 'reserveStock';
            $stock = app(WarehouseStockAvailability::class)->snapshot($intent->from_warehouse_id, $intent->product_id);
            $available = $stock['pickable_quantity'];
        }
        if ($active && !$task && $reservation->remaining_quantity === $intent->quantity
            && $reservation->quantity === $intent->quantity && $gate->allows('createPickingTask', $intent)) {
            $actions[] = 'createPickingTask';
        }
        if ($active && $staged === 0 && $gate->allows('releaseStock', $intent)) {
            $actions[] = 'releaseStock';
        }
        if ($task && $gate->allows('viewPickingTask', $intent)) {
            $actions[] = 'openPickingTask';
        }

        return [
            'reservation_status' => $reservationStatus,
            'task_status' => $task?->status,
            'remaining_quantity' => $unreserved ? $intent->quantity : $reservation->remaining_quantity,
            'staged_quantity' => $staged,
            'active_staged_quantity' => $task?->activeStagedQuantity() ?? 0,
            'dispatched_quantity' => $task?->dispatchedQuantity() ?? 0,
            'available_quantity' => $available,
            'actions' => $actions,
        ];
    }
}
