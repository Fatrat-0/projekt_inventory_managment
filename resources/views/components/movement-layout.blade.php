<x-app-layout>
    <x-slot:pageActions>
        <nav aria-label="Készletmozgások nézetei" class="flex flex-wrap items-center gap-1">
            <x-topbar-action :href="route('movements.index')" label="Mozgásnapló" short-label="Mozgások" icon="activity" :current="request()->routeIs('movements.index')" />
            <x-topbar-action :href="route('quality-returns.index')" label="Minősítés" short-label="Minősítés" icon="tasks" :current="request()->routeIs('quality-returns.index')" />
        </nav>
    </x-slot:pageActions>
    {{ $slot }}
</x-app-layout>
