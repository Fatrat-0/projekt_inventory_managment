<?php

namespace App\Support;

use App\Models\{TransferIntent, User};
use App\Policies\TransferIntentPolicy;

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
        'rejected' => 'Igény elutasítva',
        'cancelled' => 'Igény visszavonva',
    ];

    public static function workflow(TransferIntent $intent, User $user, TransferIntentPolicy $policy): array
    {
        $intent->load(['events' => fn ($events) => $events
            ->select(['id', 'transfer_intent_id', 'event_type', 'from_status', 'to_status', 'actor_id', 'occurred_at', 'reason'])
            ->with('actor:id,name')->orderBy('occurred_at')->orderBy('id')]);

        return [
            'status' => $intent->status,
            'version' => $intent->version,
            'actions' => $policy->availableActions($user, $intent),
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
}
