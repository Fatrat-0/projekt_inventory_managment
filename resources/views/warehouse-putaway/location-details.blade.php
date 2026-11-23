@include('warehouse-putaway.stock', ['stockScopes' => ['system']])
<div x-show="mode === 'suggestions' && location.ranking_explanation?.detail" data-putaway-rank-detail
     class="text-sm text-gray-700">
    <p class="font-medium">Rangsorolás</p>
    <p x-text="location.ranking_explanation?.detail"></p>
</div>
<p x-show="location.score?.same_product && location.ranking_explanation?.criterion !== 'same_product'"
   class="text-sm text-gray-700">Itt már tárolunk ebből a termékből.</p>
<dl class="grid grid-cols-1 gap-x-3 gap-y-1 text-sm sm:grid-cols-2">
    <div x-show="location.location_dimensions_cm">
        <dt class="text-gray-600">Rekeszméret (szél. × mély. × mag.)</dt>
        <dd class="font-semibold"
            x-text="['width', 'depth', 'height'].map(axis => numberValue(location.location_dimensions_cm?.[axis])).join(' × ') + ' cm'"></dd>
    </div>
    <div x-show="location.score?.nominal_weight_grams != null">
        <dt class="text-gray-600">Névleges teherbírás</dt>
        <dd class="font-semibold" x-text="mass(location.score?.nominal_weight_grams)"></dd>
    </div>
</dl>
<dl x-show="location.status !== 'rejected' && (location.score?.remaining_pallet_slots != null || location.free_pallet_slots != null)" class="text-sm">
    <dt class="text-gray-600" x-text="location.score?.remaining_pallet_slots != null ? 'Szabad raklaphely utána' : 'Jelenlegi szabad raklaphely'"></dt>
    <dd class="font-semibold" x-text="numberValue(location.score?.remaining_pallet_slots ?? location.free_pallet_slots)"></dd>
</dl>
<div x-show="location.status === 'rejected' || location.status === 'conditional'" data-putaway-fit-warnings class="text-sm"
     :class="location.status === 'rejected' && location.discrepancy_severity !== 'warning' ? 'text-rose-900' : 'text-amber-950'">
    <p class="font-medium" x-text="location.status === 'rejected' ? 'Kizáró okok' : 'Ellenőrizendő adatok'"></p>
    <ul class="list-disc space-y-0.5 ps-4">
        <template x-for="(reason, index) in checks(location.status === 'rejected' ? location.checks?.failed : location.checks?.missing)" :key="index">
            <li x-text="reason"></li>
        </template>
    </ul>
</div>
