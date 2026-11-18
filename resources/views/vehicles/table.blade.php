<div data-listing-table class="min-w-0 overflow-x-auto rounded-lg border border-gray-200 bg-white" role="region" aria-label="Járműtáblázat" tabindex="0">
    <table class="w-full table-fixed text-left text-sm text-gray-700">
        <caption class="sr-only">Nyilvántartott járművek</caption>
        <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-700">
            <tr>
                @foreach ($sortColumns as $key => $label)
                    <th scope="col" :aria-sort="sortBy === @js($key) ? sortDirection : null"
                        @class(['px-1.5 py-1', 'w-[19%]' => $key === 'name', 'w-[14%]' => $key === 'note', 'w-[20%]' => $key === 'pallet_slots_capacity', 'w-[11%]' => $key === 'active'])>
                        @include('vehicles.sort-button', ['mobile' => false])
                    </th>
                @endforeach
                <th scope="col" class="w-14 px-2"><span class="sr-only">Műveletek</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 [overflow-wrap:anywhere]">
            <template x-for="vehicle in results" :key="vehicle.id">
                <tr :data-vehicle-id="vehicle.id" class="hover:bg-gray-50">
                    <th scope="row" class="px-3 py-3 font-semibold text-gray-900" x-text="vehicle.name"></th>
                    <td class="px-3 py-3 font-mono text-xs" x-text="vehicle.registration_number || '—'"></td>
                    <td class="px-3 py-3 tabular-nums" x-text="massLabel(vehicle.payload_capacity_grams)"></td>
                    <td class="px-3 py-3 tabular-nums" x-text="slotsLabel(vehicle.pallet_slots_capacity)"></td>
                    <td class="px-3 py-3">@include('vehicles.status')</td>
                    <td class="whitespace-pre-wrap px-3 py-3" x-text="vehicle.note || '—'"></td>
                    <td class="px-1.5 py-2">@include('vehicles.actions', ['variant' => 'table'])</td>
                </tr>
            </template>
        </tbody>
    </table>
</div>
