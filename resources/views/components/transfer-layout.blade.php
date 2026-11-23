<x-app-layout>
    <x-slot:pageActions>
        @include('transfers.tabs')
    </x-slot:pageActions>
    {{ $slot }}
</x-app-layout>
