<x-app-layout>
    <div class="app-page space-y-4 py-4">
        <x-page-toolbar title="Gyorskereső" description="Oldalak és termékek keresése az alkalmazásban." />
        <form method="GET" action="{{ route('app-search.index') }}" class="flex min-w-0 flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-4">
            <label for="app-search-page" class="sr-only">Oldal vagy termék keresése</label><input id="app-search-page" type="search" name="q" value="{{ $query }}" maxlength="200" placeholder="Oldal neve, terméknév vagy cikkszám…" class="min-w-0 flex-1 basis-64 rounded-md border-gray-300 text-sm">
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Keresés</button>
        </form>
        <x-operation-feedback />
        <ul class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white">
            @forelse($results as $result)<li><a href="{{ $result['url'] }}" class="block p-4 hover:bg-indigo-50 focus-visible:ring-2 focus-visible:ring-indigo-500"><span class="text-xs text-gray-500">{{ $result['kind'] }}</span><span class="dashboard-text mt-1 block text-sm font-semibold text-gray-900">{{ $result['label'] }}</span><span class="dashboard-text block text-xs text-gray-500">{{ $result['detail'] }}</span></a></li>@empty<li class="p-6 text-sm text-gray-500">Nincs egyező oldal vagy termék.</li>@endforelse
        </ul>
        @if($moreUrl)<a href="{{ $moreUrl }}" class="inline-flex rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-indigo-700">További terméktalálatok →</a>@endif
    </div>
</x-app-layout>
