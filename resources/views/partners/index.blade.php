<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-bold text-gray-900">Partnerek <span class="text-sm font-normal text-gray-500">{{ $partners->total() }} találat</span></h2><p class="mt-1 text-sm text-gray-500">Vevők, beszállítók és elérhetőségeik.</p></div>
            <a href="{{ route('partners.create', ['context' => $context, 'type' => $filters['type']]) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új partner</a>
        </div>
        <x-operation-feedback />
        <form id="partner-filters" method="GET" action="{{ route('partners.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <input type="hidden" name="partner_id" value="{{ $filters['partner_id'] }}">
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}"><input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <div class="min-w-0 flex-1 basis-64"><label for="partner-search" class="mb-1 block text-xs font-medium text-gray-600">Partnerek keresése</label>
                <x-listing-search :items="$suggestions" :query="$filters['search']" id="partner-search" label="Partnerek keresése" placeholder="Név, e-mail vagy telefon…" filter-name="partner_id" :clear-filter-on-edit="true" results-label="Partnerjavaslatok" />
            </div>
            <div class="min-w-0 max-w-full"><label for="partner-filter-type" class="mb-1 block text-xs font-medium text-gray-600">Partner típusa</label><select id="partner-filter-type" name="type" onchange="this.form.requestSubmit()" class="max-w-full rounded-md border-gray-300 text-sm"><option value="">Összes partner</option>@foreach(\App\Models\Partner::TYPES as $value => $label)<option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="min-w-0 max-w-full"><label for="partner-contact" class="mb-1 block text-xs font-medium text-gray-600">Elérhetőség</label><select id="partner-contact" name="contact" onchange="this.form.requestSubmit()" class="max-w-full rounded-md border-gray-300 text-sm"><option value="">Összes elérhetőség</option>@foreach(\App\Queries\PartnerListing::CONTACTS as $value => $label)<option value="{{ $value }}" @selected($filters['contact'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="flex w-full flex-wrap gap-2 md:hidden">
                <div class="min-w-0 flex-1"><label for="partner-sort" class="mb-1 block text-xs text-gray-600">Rendezés</label><select id="partner-sort" onchange="this.form.elements.namedItem('sort_by').value=this.value; this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm">@foreach(\App\Queries\PartnerListing::SORTS as $key => $label)<option value="{{ $key }}" @selected($filters['sort_by'] === $key)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="partner-direction" class="mb-1 block text-xs text-gray-600">Irány</label><select id="partner-direction" onchange="this.form.elements.namedItem('sort_dir').value=this.value; this.form.requestSubmit()" class="max-w-full rounded-md border-gray-300 text-sm"><option value="asc" @selected($filters['sort_dir'] === 'asc')>Növekvő</option><option value="desc" @selected($filters['sort_dir'] === 'desc')>Csökkenő</option></select></div>
            </div>
            @if($filters['search'] !== '' || $filters['partner_id'] !== '' || $filters['type'] !== '' || $filters['contact'] !== '')
                <a href="{{ route('partners.index', array_intersect_key($filters, array_flip(['sort_by','sort_dir','per_page']))) }}" class="py-2 text-sm text-gray-600 underline">Szűrők törlése</a>
            @endif
        </form>
        @if($selectedPartner)
            <a href="{{ request()->fullUrlWithQuery(['partner_id' => null, 'page' => null]) }}" aria-label="Pontos partnerszűrés törlése" class="inline-block max-w-full break-words rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs text-indigo-800">Partner: {{ $selectedPartner->name }} · #{{ $selectedPartner->id }} ×</a>
        @endif
        <div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white md:block">
            <table class="w-full text-left text-sm text-gray-600">
                <caption class="sr-only">Vevők és beszállítók elérhetőségei</caption>
                <thead class="border-b border-gray-200 bg-gray-50 text-xs"><tr>
                    @foreach(\App\Queries\PartnerListing::SORTS as $key => $label)
                        @php($active = $filters['sort_by'] === $key)
                        @php($direction = $active && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc')
                        <th scope="col" aria-sort="{{ $active ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-4 py-3">
                            <a href="{{ route('partners.index', array_replace($filters, ['sort_by' => $key, 'sort_dir' => $direction])) }}" x-data
                                @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); const form = document.getElementById('partner-filters'); form.elements.namedItem('sort_by').value = '{{ $key }}'; form.elements.namedItem('sort_dir').value = '{{ $direction }}'; form.requestSubmit(); }" class="inline-flex gap-1 font-semibold">{{ $label }} @if($active)<span aria-hidden="true">{{ $filters['sort_dir'] === 'asc' ? '↑' : '↓' }}</span>@endif</a>
                        </th>
                    @endforeach
                    <th scope="col" class="px-4 py-3 text-right">Műveletek</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($partners as $partner)
                        <tr data-partner-id="{{ $partner->id }}" class="align-top hover:bg-gray-50">
                            <td class="max-w-sm break-words px-4 py-4"><a href="{{ route('partners.edit', ['partner' => $partner, 'context' => $context]) }}" class="font-semibold text-gray-900 hover:text-indigo-600">{{ $partner->name }}</a><p class="mt-1 text-xs text-gray-400">#{{ $partner->id }}</p></td>
                            <td class="px-4 py-4"><span class="whitespace-nowrap rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700">{{ \App\Models\Partner::TYPES[$partner->type] ?? 'Ismeretlen típus' }}</span></td>
                            <td class="max-w-xs px-4 py-4">@include('partners.contact', ['field' => 'email'])</td>
                            <td class="max-w-xs px-4 py-4">@include('partners.contact', ['field' => 'phone'])</td>
                            <td class="px-4 py-2">@include('partners.actions', ['variant' => 'table'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" role="status" class="px-4 py-12 text-center">Nincs a feltételeknek megfelelő partner.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-3 md:hidden">
            @forelse($partners as $partner)
                <article data-partner-id="{{ $partner->id }}" class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words font-semibold text-gray-900"><a href="{{ route('partners.edit', ['partner' => $partner, 'context' => $context]) }}">{{ $partner->name }}</a></h3><p class="mt-1 text-xs text-gray-500">{{ \App\Models\Partner::TYPES[$partner->type] ?? 'Ismeretlen típus' }} · #{{ $partner->id }}</p></div>@include('partners.actions', ['variant' => 'card'])</div>
                    <dl class="mt-3 space-y-3 text-sm"><div><dt class="text-xs text-gray-500">E-mail</dt><dd class="mt-1">@include('partners.contact', ['field' => 'email'])</dd></div><div><dt class="text-xs text-gray-500">Telefon</dt><dd class="mt-1">@include('partners.contact', ['field' => 'phone'])</dd></div></dl>
                </article>
            @empty
                <p role="status" class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő partner.</p>
            @endforelse
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <div class="flex flex-wrap items-center gap-3"><span>{{ $partners->firstItem() ?? 0 }}–{{ $partners->lastItem() ?? 0 }} / {{ $partners->total() }} találat</span><label for="partner-per-page" class="sr-only">Találatok oldalanként</label><select id="partner-per-page" name="per_page" form="partner-filters" onchange="this.form.requestSubmit()" class="max-w-full rounded-md border-gray-300 text-sm">@foreach([15,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select></div>
            {{ $partners->links('pagination.inventory') }}
        </div>
    </div>
</x-app-layout>
