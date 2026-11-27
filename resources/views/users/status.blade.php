<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $user->isActive() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
    {{ $user->isActive() ? 'Aktív' : 'Inaktív' }}
</span>
