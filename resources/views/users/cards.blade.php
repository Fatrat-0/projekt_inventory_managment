<div data-listing-cards>
    @forelse($users as $user)
        <article data-user-id="{{ $user->id }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <h3 class="font-semibold text-gray-900">{{ $user->name }}</h3>
            <dl class="space-y-3 text-sm"><div><dt class="text-xs text-gray-500">E-mail</dt><dd class="mt-1 break-all">{{ $user->email }}</dd></div><div><dt class="text-xs text-gray-500">Regisztráció ideje</dt><dd class="mt-1 text-gray-600">{{ $user->created_at->format('Y.m.d H:i') }}</dd></div></dl>
            <footer class="border-t border-gray-100 pt-3">@include('users.actions')</footer>
        </article>
    @empty
        <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs megjeleníthető felhasználó.</p>
    @endforelse
</div>
