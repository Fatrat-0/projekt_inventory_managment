<span class="rounded-full px-2 py-0.5 text-xs font-semibold"
      :class="{{ $advice }}.status === 'verified' ? 'bg-emerald-100 text-emerald-900' : 'bg-sky-100 text-sky-900'"
      x-text="{{ $advice }}.status === 'verified' ? 'Ellenőrzött' : 'Részben ellenőrzött'"></span>
