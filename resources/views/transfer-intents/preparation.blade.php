@php
    $preparation = $workflow['preparation'];
    $pieceCount = fn (int $value) => number_format($value, 0, ',', ' ').' db';
    $preparationLabels = $decisionOptions['preparationLabels'];
@endphp

<section aria-labelledby="intent-preparation-heading" x-show="preparation" @unless($preparation) x-cloak @endunless
         class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id="intent-preparation-heading" tabindex="-1" x-ref="preparationHeading" class="font-semibold text-gray-900">
            Készlet és előkészítés
        </h2>
        <span class="text-sm font-medium tabular-nums text-gray-800">{{ $pieceCount($intent->quantity) }} igényelve</span>
    </div>
    <p class="mt-1 break-words text-sm text-gray-600">{{ $intent->fromWarehouse->warehouse_name }}</p>

    <dl class="mt-4 grid gap-x-4 gap-y-3 border-t border-gray-100 pt-3 text-sm sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-gray-500">Foglalás</dt>
            <dd class="mt-0.5 font-medium text-gray-900" x-text="preparationLabels.reservation[preparation?.reservation_status] ?? ''">
                {{ $preparationLabels['reservation'][$preparation['reservation_status'] ?? ''] ?? '' }}
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500">Kitárolási feladat</dt>
            <dd class="mt-0.5 font-medium text-gray-900" x-text="preparationLabels.task[preparation?.task_status] ?? 'Még nincs létrehozva'">
                {{ $preparationLabels['task'][$preparation['task_status'] ?? ''] ?? 'Még nincs létrehozva' }}
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-gray-500" x-text="['active', 'consumed'].includes(preparation?.reservation_status) ? 'Lefoglalva, kitárolásra vár' : 'Még biztosítandó'">
                {{ in_array($preparation['reservation_status'] ?? null, ['active', 'consumed'], true) ? 'Lefoglalva, kitárolásra vár' : 'Még biztosítandó' }}
            </dt>
            <dd class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="pieceCount(preparation?.remaining_quantity ?? 0)">
                {{ $pieceCount($preparation['remaining_quantity'] ?? 0) }}
            </dd>
        </div>
        <div x-cloak x-show="preparation?.task_status" class="min-w-0">
            <dt class="text-gray-500">Összekészített mennyiség</dt>
            <dd class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="pieceCount(preparation?.staged_quantity ?? 0)">
                {{ $pieceCount($preparation['staged_quantity'] ?? 0) }}
            </dd>
        </div>
        <div x-cloak x-show="canAct('reserveStock') && preparation?.available_quantity != null && !fieldErrors.quantity" class="min-w-0">
            <dt class="text-gray-500">Jelenleg foglalható</dt>
            <dd class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="pieceCount(preparation?.available_quantity ?? 0)">
                {{ isset($preparation['available_quantity']) ? $pieceCount($preparation['available_quantity']) : '' }}
            </dd>
        </div>
    </dl>

    <p x-cloak x-show="canAct('reserveStock')" class="mt-3 text-sm text-gray-600">
        A foglalás az igényhez rendeli a kért mennyiséget, így más kiadásokhoz nem használható fel.
    </p>
    <p x-cloak x-show="preparation?.staged_quantity > 0" class="mt-3 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-950">
        Az összekészített áru a forrásraktárban van. A foglalás feloldása vagy az igény visszavonása előtt rögzíteni kell az áru visszahelyezését.
    </p>
    <p x-cloak x-show="preparationError" x-text="preparationError" role="alert"
       class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"></p>

    <div x-cloak x-show="preparation?.actions.length" class="mt-4 flex flex-wrap gap-2">
        <button type="button" x-show="canAct('reserveStock')" @click="submitPreparation('reserveStock')" :disabled="actionBlocked"
                class="min-h-10 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                x-text="submitting && preparationAction === 'reserveStock' ? 'Biztosítás…' : 'Készlet biztosítása'">Készlet biztosítása</button>
        <button type="button" x-show="canAct('createPickingTask')" @click="submitPreparation('createPickingTask')" :disabled="actionBlocked"
                class="min-h-10 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                x-text="submitting && preparationAction === 'createPickingTask' ? 'Létrehozás…' : 'Kitárolási feladat létrehozása'">Kitárolási feladat létrehozása</button>
        <a x-show="canAct('openPickingTask')" href="{{ route('warehouse-picking-pages.show', $intent) }}"
           class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
            Kitárolási feladat megnyitása
        </a>
        <button type="button" x-show="canAct('releaseStock')" @click="openDecision('releaseStock', $event.currentTarget)" :disabled="actionBlocked"
                aria-haspopup="dialog" class="min-h-10 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
            Foglalás feloldása
        </button>
    </div>
</section>
