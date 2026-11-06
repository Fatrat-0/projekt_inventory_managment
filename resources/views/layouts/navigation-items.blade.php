@foreach($navigationGroups as $group)
    <div class="mb-5 last:mb-0">
        <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ $group['label'] }}</p>
        <ul aria-label="{{ $group['label'] }}" class="space-y-1">
            @foreach($group['items'] as $item)
                @php $active = request()->routeIs(...$item['patterns']); @endphp
                <li><a href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif
                    class="flex min-h-11 items-center gap-3 rounded-md border-l-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $active ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <x-icon :name="$item['icon']" class="h-5 w-5" /><span>{{ $item['label'] }}</span>
                </a></li>
            @endforeach
        </ul>
    </div>
@endforeach
