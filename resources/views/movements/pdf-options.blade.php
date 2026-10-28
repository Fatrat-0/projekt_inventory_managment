<div x-data="{
    open: false,
    orientation: 'portrait',
    showWarehouse: true,
    showReference: true,
    showUser: true,
    showSummary: true,
    showSignatures: true,
    baseUrl: {{ Illuminate\Support\Js::from(route('export.movements', $filters)) }},
    get previewUrl() {
        const url = new URL(this.baseUrl);
        const setParam = (key, val) => url.searchParams.set(key, val ? '1' : '0');
        
        url.searchParams.set('orientation', this.orientation);
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
}" class="inline-block" @keydown.escape.window="open = false">

    <button @click="open = true" type="button" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 text-sm font-semibold py-2 px-3 rounded-md shadow-sm inline-flex items-center gap-2 transition">
        <x-icon name="document" /> Napló (PDF)
    </button>

    <div role="dialog" aria-modal="true" aria-label="Mozgásnapló PDF beállításai" x-show="open" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-60 flex items-center justify-center p-4">
        <div @click.away="open = false" class="bg-white rounded-lg shadow-2xl w-full max-w-6xl overflow-hidden flex flex-col md:flex-row" style="height: 88vh;">
            
            <div class="w-full h-1/2 md:h-full md:w-1/3 bg-gray-50 p-5 border-r border-gray-200 flex flex-col justify-between overflow-y-auto">
                <div class="space-y-4">
                    <div class="flex justify-between items-center border-b pb-2">
                        <h3 class="text-lg font-bold text-gray-800">Mozgásnapló beállításai</h3>
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
                    <a :href="previewUrl" target="_blank" rel="noopener" class="block text-center text-sm text-indigo-600 underline">Előnézet megnyitása külön lapon</a>
                    <button @click="open = false" type="button" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1.5 px-4 rounded text-sm transition">Bezárás</button>
                </div>
            </div>

            <div class="w-full h-1/2 md:h-full md:w-2/3 bg-gray-200 p-2 flex flex-col">
                <template x-if="open">
                    <iframe title="Mozgásnapló előnézete" :src="previewUrl" class="w-full h-full rounded border border-gray-300 bg-white shadow-inner"></iframe>
                </template>
            </div>
        </div>
    </div>
</div>