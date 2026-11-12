<a x-show="{{ $advice }}.plan_url" :href="{{ $advice }}.plan_url" target="_blank" rel="noopener"
   class="inline-flex min-h-10 items-center text-xs font-medium text-indigo-700 hover:underline">
    Tervrajzon megtekintés<span class="sr-only"> (új lapon)</span>
</a>
<span x-show="!{{ $advice }}.positioned" class="text-xs text-gray-600">Nincs elhelyezve a rajzon.</span>
