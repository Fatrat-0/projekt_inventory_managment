<section aria-label="Lehetséges duplikációk" class="space-y-3" aria-live="polite">
    <p x-show="loading" style="display:none" class="text-xs text-gray-500">Egyezések ellenőrzése…</p>
    <div x-show="failed" style="display:none" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        Az ellenőrzés nem sikerült. Mentéskor a szerver újra ellenőrzi az egyezéseket.
        <button type="button" @click="check()" class="ml-1 underline">Újrapróbálás</button>
    </div>
    <div x-show="total > 0" @if($duplicates['total'] === 0) style="display:none" @endif class="space-y-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <p class="font-semibold">Lehetséges egyezés meglévő partnerrel</p>
        <p>Azonos név vagy e-mail-cím található a nyilvántartásban. Tekintsd át az egyezéseket; külön partnerként is mentheted az adatokat.</p>
        @if(session('duplicate_warning'))<p class="font-medium">A folytatáshoz erősítsd meg lent, hogy átnézted az egyezéseket.</p>@endif
        <ul class="space-y-2">
            <template x-for="match in matches" :key="match.id">
                <li class="rounded border border-amber-200 bg-white p-2">
                    <a :href="match.edit_url" target="_blank" rel="noopener" class="break-words font-semibold underline" x-text="match.name + ' · #' + match.id"></a>
                    <span x-show="match.archived" class="ml-1 text-xs">Archivált</span>
                    <p class="break-all text-xs" x-text="match.email || 'E-mail nincs megadva'"></p>
                    <p class="text-xs" x-text="[match.same_name ? 'Egyező név' : '', match.same_email ? 'Egyező e-mail' : ''].filter(Boolean).join(' · ')"></p>
                </li>
            </template>
            <noscript>
                @foreach($duplicates['matches'] as $match)
                    <li class="rounded border border-amber-200 bg-white p-2">
                        <a href="{{ $match['edit_url'] }}" target="_blank" rel="noopener" class="break-words font-semibold underline">{{ $match['name'] }} · #{{ $match['id'] }}</a>
                        @if($match['archived'])<span class="ml-1 text-xs">Archivált</span>@endif
                        <p class="break-all text-xs">{{ $match['email'] ?: 'E-mail nincs megadva' }}</p>
                        <p class="text-xs">{{ collect([$match['same_name'] ? 'Egyező név' : '', $match['same_email'] ? 'Egyező e-mail' : ''])->filter()->implode(' · ') }}</p>
                    </li>
                @endforeach
            </noscript>
        </ul>
        <p x-show="total > matches.length" x-text="'Az első ' + matches.length + ' találat látható, összesen ' + total + ' egyezésből.'"
            @if($duplicates['total'] <= count($duplicates['matches'])) style="display:none" @endif class="text-xs">Az első {{ count($duplicates['matches']) }} találat látható, összesen {{ $duplicates['total'] }} egyezésből.</p>
        <label class="flex items-start gap-2">
            <input type="checkbox" name="confirm_duplicates" value="1" x-model="acknowledged" :required="total > 0" :disabled="total === 0 || loading"
                @checked($duplicateAcknowledged) @required($duplicates['total'] > 0) class="mt-0.5 rounded border-gray-300 text-indigo-600">
            <span>Átnéztem az egyezéseket, külön partnerként mentem.</span>
        </label>
    </div>
    <input type="hidden" name="duplicate_token" :value="token" value="{{ $duplicates['token'] }}">
</section>
