@php
    use App\Support\TransferIntentDisplay;

    $nextSteps = [
        'pending_approval' => 'Az igény a forrásraktár jóváhagyására vár.',
        'approved' => 'Az igényt jóváhagyták. A készlet biztosítása külön művelet.',
        'rejected' => 'Az igényt elutasították.',
        'cancelled' => 'Az igényt visszavonták.',
    ];
    $decisionCopy = [
        'approve' => [
            'label' => 'Jóváhagyás', 'confirm' => 'Igény jóváhagyása',
            'reason' => 'Jóváhagyás indoka (opcionális)', 'requiresReason' => false,
            'description' => 'A jóváhagyott igényhez külön művelettel lehet biztosítani a készletet.',
            'success' => 'Az igényt jóváhagytad.',
        ],
        'reject' => [
            'label' => 'Elutasítás', 'confirm' => 'Igény elutasítása',
            'reason' => 'Elutasítás indoka', 'requiresReason' => true,
            'description' => 'Az elutasítás lezárja az igényt. Az indok megjelenik az eseménytörténetben.',
            'success' => 'Az igényt elutasítottad.',
        ],
        'cancel' => [
            'label' => 'Visszavonás', 'confirm' => 'Igény visszavonása',
            'reason' => 'Visszavonás indoka', 'requiresReason' => true,
            'description' => 'A visszavonás lezárja az igényt. Az indok megjelenik az eseménytörténetben.',
            'success' => 'Az igényt visszavontad.',
        ],
    ];
    $preparationCopy = [
        'reserveStock' => ['success' => 'A forráskészletet biztosítottad.'],
        'createPickingTask' => ['success' => 'A kitárolási feladat létrejött.'],
        'releaseStock' => [
            'label' => 'Foglalás feloldása', 'confirm' => 'Készletfoglalás feloldása',
            'reason' => 'Feloldás indoka (opcionális)', 'requiresReason' => false,
            'description' => 'A készlet ismét felhasználható lesz. A még el nem kezdett kitárolási feladat megszűnik.',
            'success' => 'A készletfoglalást feloldottad.',
        ],
    ];
    $decisionOptions = [
        'workflow' => $workflow,
        'workflowUrl' => route('transfer-intents.show', ['transferIntent' => $intent, 'workflow' => 1]),
        'actionUrls' => collect(array_keys($decisionCopy))->mapWithKeys(fn ($action) => [
            $action => ['url' => route('transfer-intents.'.$action, $intent), 'method' => 'PATCH'],
        ])->all() + [
            'reserveStock' => ['url' => route('transfer-intents.stock-reservation.store', $intent), 'method' => 'POST'],
            'releaseStock' => ['url' => route('transfer-intents.stock-reservation.release', $intent), 'method' => 'PATCH'],
            'createPickingTask' => ['url' => route('transfer-intents.picking-task.store', $intent), 'method' => 'POST'],
        ],
        'statusLabels' => TransferIntentDisplay::STATUS,
        'statusColors' => TransferIntentDisplay::STATUS_STYLE,
        'nextSteps' => $nextSteps,
        'eventLabels' => TransferIntentDisplay::EVENTS,
        'decisions' => $decisionCopy + $preparationCopy,
        'preparationLabels' => [
            'reservation' => [
                'unreserved' => 'Nincs készletfoglalás',
                'active' => 'Aktív foglalás',
                'released' => 'Foglalás feloldva',
                'consumed' => 'Foglalás teljesen kitárolva',
            ],
            'task' => [
                'pending' => 'Kitárolásra vár',
                'in_progress' => 'Kitárolás folyamatban',
                'picked' => 'Összekészítve',
            ],
        ],
    ];
    $requestedArrival = TransferIntentDisplay::requestedArrival($intent);
@endphp

<x-transfer-layout>
    <div class="app-page mx-auto max-w-6xl space-y-4 py-4 [&_h1]:break-all [&_h1]:font-mono [&_h1]:text-base sm:[&_h1]:text-xl"
         x-data="transferIntentDecision(@js($decisionOptions))">
        <a href="{{ route('transfer-intent-pages.index') }}" class="inline-flex min-h-9 items-center text-sm font-medium text-indigo-700 hover:underline">← Átmozgatási igények</a>

        <x-page-toolbar :title="$intent->reference">
            <span data-intent-status class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
                  :class="statusColors[workflow.status]" x-text="statusLabels[workflow.status]">
                {{ TransferIntentDisplay::STATUS[$intent->status] }}
            </span>
        </x-page-toolbar>
        <x-operation-feedback />
        @if(request()->query('submitted') === '1')
            <p role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                Az átmozgatási igényt benyújtottad.
            </p>
        @endif
        <p x-cloak x-show="statusNotice" role="status" x-text="statusNotice"
           class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"></p>
        <div x-cloak x-show="!activeAction && (conflict || committedAction || refreshError)"
             class="flex flex-wrap items-center gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <p role="alert" x-text="refreshError || submissionError"></p>
            <button type="button" x-ref="refreshOutsideDialog" class="min-h-9 font-semibold underline underline-offset-2 disabled:opacity-50"
                    :disabled="refreshing" @click="refreshWorkflow()">Aktuális adatok frissítése</button>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="min-w-0 space-y-4">
                <section aria-labelledby="intent-details-heading" class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                    <h2 id="intent-details-heading" class="font-semibold text-gray-900">Igény adatai</h2>
                    <div class="mt-3 flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 pb-3">
                        <div class="min-w-0">
                            <p class="break-words font-semibold text-gray-900">{{ $intent->product->product_name }}</p>
                            <p class="break-all font-mono text-xs text-gray-500">{{ $intent->product->sku }}</p>
                        </div>
                        <p class="shrink-0 font-semibold tabular-nums text-gray-900">{{ number_format($intent->quantity, 0, ',', ' ') }} db</p>
                    </div>
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="text-gray-500">Forrásraktár</dt>
                            <dd class="mt-0.5 break-words font-medium text-gray-900">{{ $intent->fromWarehouse->warehouse_name }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Célraktár</dt>
                            <dd class="mt-0.5 break-words font-medium text-gray-900">{{ $intent->toWarehouse->warehouse_name }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Prioritás</dt>
                            <dd class="mt-0.5 font-medium text-gray-900">{{ TransferIntentDisplay::PRIORITY[$intent->priority] }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Kért érkezési határidő</dt>
                            <dd class="mt-0.5 font-medium text-gray-900">{{ $requestedArrival ?? 'Nincs megadva' }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Fuvarpreferencia</dt>
                            <dd class="mt-0.5 font-medium text-gray-900">{{ TransferIntentDisplay::SHIPPING[$intent->shipping_preference] }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Kezdeményező</dt>
                            <dd class="mt-0.5 break-words font-medium text-gray-900">{{ $intent->requester->name }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-gray-500">Létrehozva</dt>
                            <dd class="mt-0.5 font-medium text-gray-900">{{ $intent->created_at?->copy()->timezone('Europe/Budapest')->format('Y. m. d. H:i') ?? 'Nincs rögzítve' }}</dd>
                        </div>
                        @foreach(['reason' => 'Igénylés indoka', 'note' => 'Megjegyzés'] as $field => $label)
                            @if($intent->{$field} !== null && $intent->{$field} !== '')
                                <div class="min-w-0 sm:col-span-2">
                                    <dt class="text-gray-500">{{ $label }}</dt>
                                    <dd class="mt-0.5 whitespace-pre-wrap break-words text-gray-900">{{ $intent->{$field} }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </section>

                @include('transfer-intents.preparation')
                <section aria-labelledby="intent-workflow-heading" x-show="!preparation || canAct('cancel')"
                         @if($workflow['preparation'] && !in_array('cancel', $workflow['actions'], true)) x-cloak @endif
                         class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                    <h2 id="intent-workflow-heading" tabindex="-1" x-ref="workflowHeading" class="font-semibold text-gray-900"
                        x-text="preparation ? 'Igény kezelése' : 'Következő lépés'">{{ $workflow['preparation'] ? 'Igény kezelése' : 'Következő lépés' }}</h2>
                    <p x-show="!preparation" class="mt-2 text-sm text-gray-700" x-text="nextSteps[workflow.status]">{{ $nextSteps[$intent->status] }}</p>
                    <div x-cloak x-show="workflow.actions.length" class="mt-3 flex flex-wrap gap-2">
                        @foreach($decisionCopy as $action => $copy)
                            <button type="button" x-show="workflow.actions.includes(@js($action))"
                                    @click="openDecision(@js($action), $event.currentTarget)" :disabled="actionBlocked"
                                    aria-haspopup="dialog"
                                    class="min-h-10 rounded-md px-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50
                                        {{ $action === 'approve' ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50' }}">
                                {{ $copy['label'] }}
                            </button>
                        @endforeach
                    </div>
                </section>
            </div>

            <section aria-labelledby="intent-history-heading" class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h2 id="intent-history-heading" class="font-semibold text-gray-900">Eseménytörténet</h2>
                <p x-cloak x-show="!workflow.events.length" class="mt-3 text-sm text-gray-600">Ehhez az igényhez még nincs rögzített esemény.</p>
                <ol class="mt-2 divide-y divide-gray-100 text-sm">
                    <template x-for="event in workflow.events" :key="event.id">
                        <li class="grid min-w-0 gap-1 py-3">
                            <div class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                                <span class="font-semibold text-gray-900" x-text="eventLabels[event.event_type] ?? 'Állapotváltozás'"></span>
                                <time class="text-xs text-gray-500" :datetime="event.occurred_at" x-text="eventDate(event.occurred_at)"></time>
                            </div>
                            <p class="break-words text-gray-600" x-text="event.actor_name"></p>
                            <p x-show="event.reason" class="whitespace-pre-wrap break-words text-gray-800" x-text="event.reason"></p>
                        </li>
                    </template>
                </ol>
            </section>
        </div>

        <dialog x-ref="decisionDialog" aria-modal="true" aria-labelledby="intent-decision-heading" aria-describedby="intent-decision-description"
                @close="decisionClosed()" @cancel="if (submitting || refreshing) $event.preventDefault()"
                class="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-lg border border-gray-200 p-0 shadow-xl backdrop:bg-gray-900/50">
            <form @submit.prevent="confirmDecision()" class="space-y-4 p-4 sm:p-5">
                <div>
                    <h2 id="intent-decision-heading" class="text-lg font-semibold text-gray-900" x-text="decision?.confirm"></h2>
                    <p id="intent-decision-description" class="mt-1 text-sm text-gray-600" x-text="decision?.description"></p>
                </div>
                <div>
                    <label for="intent-decision-reason" class="block text-sm font-medium text-gray-800" x-text="decision?.reason"></label>
                    <textarea id="intent-decision-reason" x-ref="reason" rows="4" maxlength="500"
                              :value="drafts[activeAction] ?? ''" @input="drafts[activeAction] = $event.target.value; delete fieldErrors.reason"
                              :required="decision?.requiresReason" :aria-invalid="!!fieldErrors.reason"
                              :aria-describedby="fieldErrors.reason ? 'intent-decision-reason-error' : null"
                              :readonly="submitting || !!committedAction"
                              class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-600 focus:ring-indigo-600 aria-invalid:border-red-600"></textarea>
                    <p id="intent-decision-reason-error" x-show="fieldErrors.reason" x-text="fieldErrors.reason?.[0]" class="mt-1 text-sm text-red-700"></p>
                </div>
                <div x-show="submissionError || refreshError || (!actionAvailable && !refreshing) || committedAction" aria-live="polite"
                     class="space-y-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p x-show="submissionError" role="alert" x-text="submissionError"></p>
                    <p x-show="refreshError" role="alert" x-text="refreshError"></p>
                    <p x-show="!actionAvailable && !refreshing">Ez a művelet az aktuális állapotban már nem érhető el.</p>
                    <button x-ref="refreshInDialog" x-show="conflict || committedAction || refreshError" type="button"
                            :disabled="refreshing || submitting" @click="refreshWorkflow()"
                            class="min-h-9 font-semibold underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50">
                        Aktuális adatok frissítése
                    </button>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button type="button" @click="closeDecision()" :disabled="submitting || refreshing"
                            class="min-h-10 rounded-md border border-gray-300 px-3 text-sm font-medium text-gray-800 hover:bg-gray-50 disabled:opacity-50"
                            x-text="actionAvailable && !committedAction ? 'Mégse' : 'Bezárás'">Mégse</button>
                    <button type="submit" :disabled="submitting || refreshing || conflict || !actionAvailable || !!committedAction"
                            class="min-h-10 rounded-md px-3 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            :class="activeAction === 'approve' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-red-700 hover:bg-red-800'"
                            x-text="submitting ? 'Mentés…' : decision?.confirm"></button>
                </div>
            </form>
        </dialog>
    </div>
</x-transfer-layout>
