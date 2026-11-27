<div data-listing-cards>
    @forelse($users as $user)
        <article data-user-id="{{ $user->id }}" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="[overflow-wrap:anywhere] font-semibold"><a href="{{ route('users.show', $user) }}" class="text-indigo-700 hover:underline">{{ $user->name }}</a></h2>
                    <p class="mt-1 break-all text-sm text-gray-600">{{ $user->email }}</p>
                </div>
                @include('users.actions', ['variant' => 'card'])
            </div>
            <dl class="grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 text-sm">
                <div><dt class="text-xs text-gray-500">Globális szerepkör</dt><dd>{{ \App\Models\User::ROLES[$user->role] }}</dd></div>
                <div><dt class="text-xs text-gray-500">Állapot</dt><dd>@include('users.status')</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Raktári hozzáférés</dt><dd class="[overflow-wrap:anywhere]">@include('users.warehouse-summary')</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Fiók létrehozása</dt><dd>{{ $user->created_at?->format('Y.m.d. H:i') ?? '–' }}</dd></div>
            </dl>
            @if($onlyUsableAdmin && $user->isUsableAdministrator())
                <p class="border-t border-gray-100 pt-3 text-xs text-gray-600">Az utolsó használható adminisztrátori fiók.</p>
            @elseif($user->password_change_required)
                <p class="border-t border-gray-100 pt-3 text-xs text-gray-600">Saját jelszó beállítására vár.</p>
            @endif
        </article>
    @empty
        <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-600">Nincs a feltételeknek megfelelő fiók.</p>
    @endforelse
</div>
