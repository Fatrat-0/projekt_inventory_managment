@props(['location', 'params'])
<a href="{{ route('warehouses.relocations.create', $params + ['from_location_id' => $location->id]) }}"
   class="inline-flex min-h-10 items-center rounded-md px-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
    Áthelyezés innen
</a>
