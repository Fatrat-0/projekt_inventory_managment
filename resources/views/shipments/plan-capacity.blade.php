<section class="{{ $panel }}" data-plan-capacity aria-labelledby="plan-capacity-heading">
    <h2 id="plan-capacity-heading" class="text-base font-semibold text-gray-900">Járműkapacitás</h2>
    <table class="mt-3 w-full table-fixed border-collapse text-left text-xs [overflow-wrap:anywhere] sm:text-sm">
        <thead class="border-b border-gray-200 text-gray-600">
            <tr><th scope="col" class="pb-2 pe-1 font-medium">Ellenőrzés</th><th scope="col" class="pb-2 pe-1 font-medium">Rakomány</th><th scope="col" class="pb-2 pe-1 font-medium">Járműkorlát</th><th scope="col" class="pb-2 font-medium">Maradék</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <template x-for="row in capacityRows" :key="row.key">
                <tr :class="row.status === 'exceeded' ? 'bg-amber-50 text-amber-950' : 'text-gray-800'">
                    <th scope="row" class="py-2 pe-1 align-top font-semibold" x-text="row.name"></th>
                    <td class="py-2 pe-1 align-top tabular-nums" x-text="row.required == null ? 'Igazolásra vár' : capacityValue(row, row.required)"></td>
                    <td class="py-2 pe-1 align-top tabular-nums" x-text="row.limit == null ? 'Nincs adat' : capacityValue(row, row.limit)"></td>
                    <td class="py-2 align-top tabular-nums">
                        <span x-show="row.status === 'exceeded'" class="block font-semibold">Túllépés</span>
                        <span x-text="row.remaining == null ? '—' : capacityValue(row, Math.abs(row.remaining))"></span>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>
    <p class="mt-2 text-xs text-gray-600" x-text="readiness?.capacity?.message"></p>
</section>
