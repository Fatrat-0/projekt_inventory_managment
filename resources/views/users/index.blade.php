<x-app-layout>


    <div class="py-4" data-listing-page="users" x-data="listingView('users')" :data-listing-view="viewMode" @resize.window.debounce.100ms="closeMenus()">
        <div class="app-page space-y-4">
            <x-page-toolbar title="Felhasználók">
                <a href="{{ route('users.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új Felhasználó</a>
                <x-listing-view-toggle />
            </x-page-toolbar>

            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative shadow-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative shadow-sm">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div data-listing-table class="bg-white overflow-x-auto shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs text-gray-700 uppercase">
                        <tr>
                            <th data-listing-column="name" x-show="columnVisible('name')" class="px-6 py-3 text-left">Név</th>
                            <th data-listing-column="email" x-show="columnVisible('email')" class="px-6 py-3 text-left">E-mail</th>
                            <th data-listing-column="created_at" x-show="columnVisible('created_at')" class="px-6 py-3 text-left">Regisztráció ideje</th>
                            <th data-listing-column="actions" x-show="columnVisible('actions')" class="px-6 py-3 text-right"><span class="sr-only">Műveletek</span><x-listing-columns /></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($users as $user)
                        <tr data-user-id="{{ $user->id }}" class="hover:bg-gray-50">
                            <td data-listing-column="name" x-show="columnVisible('name')" class="px-6 py-4 font-medium text-gray-900">{{ $user->name }}</td>
                            <td data-listing-column="email" x-show="columnVisible('email')" class="px-6 py-4">{{ $user->email }}</td>
                            <td data-listing-column="created_at" x-show="columnVisible('created_at')" class="px-6 py-4 text-gray-500">{{ $user->created_at->format('Y.m.d H:i') }}</td>
                            <td data-listing-column="actions" x-show="columnVisible('actions')" class="px-6 py-4 text-right">
                                @include('users.actions')
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('users.cards')
            <div class="mt-4">
                {{ $users->links() }}
            </div>

        </div>
    </div>
</x-app-layout>
