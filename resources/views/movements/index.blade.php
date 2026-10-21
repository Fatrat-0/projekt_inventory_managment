<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Készletmozgások Története') }}
            </h2>
            <a href="{{ route('movements.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                + Új Mozgás Rögzítése
            </a>
            <!-- Készletmozgások PDF Előnézet Modal -->
<div x-data="{
    open: false,
    orientation: 'portrait',
    typeFilter: 'all',
    showWarehouse: true,
    showReference: true,
    showUser: true,
    showSummary: true,
    showSignatures: true,
    baseUrl: '{{ route('export.movements') }}',
    get previewUrl() {
        const url = new URL(this.baseUrl);
        const setParam = (key, val) => url.searchParams.set(key, val ? '1' : '0');
        
        url.searchParams.set('orientation', this.orientation);
        if (this.typeFilter !== 'all') url.searchParams.set('type', this.typeFilter); else url.searchParams.delete('type');
        setParam('show_warehouse', this.showWarehouse);
        setParam('show_reference', this.showReference);
        setParam('show_user', this.showUser);
        setParam('show_summary', this.showSummary);
        setParam('show_signatures', this.showSignatures);
        url.searchParams.set('preview', '1');
        return url.toString();
    },
    get downloadUrl() {
        const url = new URL(this.previewUrl);
        url.searchParams.delete('preview');
        return url.toString();
    }
}" class="inline-block">

    <button @click="open = true" type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded shadow inline-flex items-center gap-2 transition">
        Forgalmi napló (PDF)
    </button>

    <div x-show="open" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-60 flex items-center justify-center p-4">
        <div @click.away="open = false" class="bg-white rounded-lg shadow-2xl w-full max-w-6xl overflow-hidden flex flex-col md:flex-row" style="height: 88vh;">
            
            <div class="w-full md:w-1/3 bg-gray-50 p-5 border-r border-gray-200 flex flex-col justify-between overflow-y-auto">
                <div class="space-y-4">
                    <div class="flex justify-between items-center border-b pb-2">
                        <h3 class="text-lg font-bold text-gray-800">📄 Mozgásnapló Beállításai</h3>
                        <button @click="open = false" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
                    </div>

                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Lap tájolása</p>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="orientation = 'portrait'" :class="orientation === 'portrait' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">▯ Álló (A4)</button>
                            <button type="button" @click="orientation = 'landscape'" :class="orientation === 'landscape' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">▭ Fekvő (A4)</button>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Mozgás típusa</p>
                        <select x-model="typeFilter" class="w-full rounded border-gray-300 text-sm">
                            <option value="all">Minden mozgás (Bevétel + Kiadás)</option>
                            <option value="in">Csak Bevételezések (▲ IN)</option>
                            <option value="out">Csak Kiadások (▼ OUT)</option>
                        </select>
                    </div>

                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Megjelenő oszlopok és elemek</p>
                        <div class="space-y-2 bg-white p-3 rounded border border-gray-200">
                            <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" x-model="showWarehouse" class="rounded border-gray-300 text-indigo-600">
                                <span>Érintett raktár oszlop</span>
                            </label>
                            <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" x-model="showReference" class="rounded border-gray-300 text-indigo-600">
                                <span>Hivatkozás / Megjegyzés oszlop</span>
                            </label>
                            <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" x-model="showUser" class="rounded border-gray-300 text-indigo-600">
                                <span>Rögzítő felhasználó neve</span>
                            </label>
                            <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" x-model="showSummary" class="rounded border-gray-300 text-indigo-600">
                                <span>Forgalmi összesítő táblázat</span>
                            </label>
                            <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" x-model="showSignatures" class="rounded border-gray-300 text-indigo-600">
                                <span>Aláírási mezők</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 pt-4 border-t mt-4">
                    <a :href="downloadUrl" @click="open = false" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 px-4 rounded shadow transition flex items-center justify-center gap-2">
                        PDF Letöltése
                    </a>
                    <button @click="open = false" type="button" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1.5 px-4 rounded text-sm transition">Bezárás</button>
                </div>
            </div>

            <div class="w-full md:w-2/3 bg-gray-200 p-2 flex flex-col">
                <template x-if="open">
                    <iframe :src="previewUrl" class="w-full h-full rounded border border-gray-300 bg-white shadow-inner"></iframe>
                </template>
            </div>
        </div>
    </div>
</div>
        </div>
        
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Dátum</th>
                            <th class="px-6 py-3">Irány</th>
                            <th class="px-6 py-3">Termék</th>
                            <th class="px-6 py-3">Raktár</th>
                            <th class="px-6 py-3 text-right">Mennyiség</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($movements as $movement)
                        <tr class="border-b">
                            <td class="px-6 py-4">{{ $movement->created_at->format('Y.m.d H:i') }}</td>
                            <td class="px-6 py-4 font-bold {{ $movement->type == 'in' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $movement->type == 'in' ? 'BEVÉTEZÉS (IN)' : 'KIADÁS (OUT)' }}
                            </td>
                            <td class="px-6 py-4">{{ $movement->product->product_name }}</td>
                            <td class="px-6 py-4">{{ $movement->warehouse->warehouse_name }}</td>
                            <td class="px-6 py-4 text-right font-bold">{{ $movement->quantity }} db</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>