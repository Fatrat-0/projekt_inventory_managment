@include('warehouse-putaway.stock', ['stockScopes' => ['system']])
<ul x-show="location.ranking_notes?.length" class="list-disc space-y-0.5 ps-4 text-sm text-gray-700">
    <template x-for="note in location.ranking_notes ?? []" :key="note">
        <li x-text="note"></li>
    </template>
</ul>
<dl x-show="location.status !== 'rejected' && (location.score?.remaining_pallet_slots != null || location.free_pallet_slots != null)" class="text-sm">
    <dt class="text-gray-600" x-text="location.score?.remaining_pallet_slots != null ? 'Szabad raklaphely utána' : 'Jelenlegi szabad raklaphely'"></dt>
    <dd class="font-semibold" x-text="numberValue(location.score?.remaining_pallet_slots ?? location.free_pallet_slots)"></dd>
</dl>
<ul x-show="location.status === 'rejected' || location.status === 'conditional'" class="list-disc space-y-0.5 ps-4 text-xs"
    :class="location.status === 'rejected' ? 'text-red-900' : 'text-amber-900'">
    <template x-for="(reason, index) in checks(location.status === 'rejected' ? location.checks?.failed : location.checks?.missing)" :key="index">
        <li x-text="reason"></li>
    </template>
</ul>
