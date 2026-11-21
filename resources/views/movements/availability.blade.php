@php($compact = ($compactIssue ?? false) ? "type === 'out'" : 'false')
<div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
    <div role="status" aria-label="Készletelérhetőség" aria-live="polite">
        <p x-show="!shownBalance" x-text="({{ $compact }}) ? 'A készletadatok a termék és a raktár ellenőrzése után jelennek meg.' : stockText">A készletadatok a termék és a raktár kiválasztása után jelennek meg.</p>
        <dl x-cloak x-show="shownBalance" class="grid gap-x-2 gap-y-2 sm:grid-cols-3" :class="({{ $compact }}) ? 'grid-cols-3' : 'grid-cols-2'">
            <template x-for="[label, quantity] in (({{ $compact }}) ? stockLines.slice(0, 3) : stockLines)" :key="label">
                <div class="min-w-0">
                    <dt class="text-xs text-gray-600 [overflow-wrap:anywhere]" x-text="label"></dt>
                    <dd class="font-semibold tabular-nums [overflow-wrap:anywhere]" :class="({{ $compact }}) ? 'mt-0.5 sm:text-lg' : ''" x-text="formatStock(quantity)"></dd>
                </div>
            </template>
        </dl>
        <p x-cloak x-show="shownBalance && !shownBalance.consistent" class="mt-2 font-medium text-amber-800">Eltérő készletadatok miatt az elérhetőség nem számítható.</p>
        <p x-cloak x-show="!({{ $compact }}) && type === 'out' && shownBalance?.direct_issue_quantity != null" class="mt-2 text-xs">
            Közvetlen kiadás felső határa <strong x-text="formatStock(shownBalance?.direct_issue_quantity ?? null)"></strong>.
        </p>
    </div>
    @if($compactIssue ?? false)
        <div x-cloak x-show="type === 'out' && canLoadSources" class="mt-2 flex flex-wrap items-start justify-between gap-x-3 gap-y-1 text-xs">
            <details x-show="stockLines.length > 3" class="min-w-0">
                <summary class="min-h-8 cursor-pointer py-1.5 font-medium text-gray-700">Készletrészletek</summary>
                <dl class="flex flex-wrap gap-x-5 gap-y-2 py-1">
                    <template x-for="[label, quantity] in stockLines.slice(3)" :key="label">
                        <div>
                            <dt x-text="label" class="text-gray-600"></dt>
                            <dd x-text="formatStock(quantity)" class="font-semibold tabular-nums"></dd>
                        </div>
                    </template>
                </dl>
            </details>
            <button type="button" @click="refreshIssueOptions(); refreshSources()" :disabled="sourceLoading || issueOptionLoading" class="ml-auto min-h-8 text-indigo-700 underline disabled:opacity-50">Elérhetőség frissítése</button>
        </div>
    @endif
    <button type="button" x-cloak x-show="!({{ $compact }}) && product && warehouse" @click="loadStock()" class="mt-2 text-indigo-700 underline">Frissítés</button>
    <p x-show="!({{ $compact }})" class="mt-1 text-xs text-gray-600">Az alkalmazás mentéskor újra ellenőrzi a kiadható mennyiséget.</p>
</div>
