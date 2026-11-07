@if(auth()->id() !== $user->id)
    <form action="{{ route('users.destroy', $user) }}" method="POST" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a felhasználót a rendszerből?');">
        @csrf @method('DELETE')
        <button type="submit" class="rounded border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 hover:text-red-900">Törlés</button>
    </form>
@else
    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Te vagy az</span>
@endif
