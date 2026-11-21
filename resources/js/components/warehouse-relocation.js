import intentSubmission from './transfer-intent-submission';
import transferQuantity, { positiveInteger } from './transfer-quantity';
import readJson from './warehouse-json';
import stockCaption from './putaway-stock-caption';

const number = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const axes = ['width', 'depth', 'height'];
const fields = ['products', 'warehouses', 'sources', 'cargo', 'lookup', 'preview'];
const statusNames = { verified: 'Ellenőrzött', conditional: 'Feltételes', rejected: 'Nem alkalmas' };

function integer(value, min, max) {
    const text = String(value).trim();
    const parsed = Number(text);
    return /^\d+$/.test(text) && Number.isSafeInteger(parsed) && parsed >= min && parsed <= max ? parsed : null;
}

function weight(value) {
    const text = value.trim().replace(',', '.');
    return /^\d+(?:\.\d{1,3})?$/.test(text) && Number(text) > 0 && Number(text) <= 1_000_000 ? text : null;
}

const warehouseOption = row => ({ value: String(row.warehouse_id), label: row.warehouse_name });
const productOption = row => ({ value: String(row.product_id), label: row.product_name + ' · ' + row.sku });

export default config => Object.defineProperties({
    ...intentSubmission(result => Boolean(result?.relocation?.id)),
    requests: {},
    productTimer: null,
    cargoTimer: null,
    allWarehouses: config.warehouses.map(warehouseOption),
    warehouseOptions: config.warehouses.map(warehouseOption),
    productMatches: [],
    productTerm: '',
    warehouseId: config.warehouseId,
    productId: config.initialProduct ? String(config.initialProduct.product_id) : '',
    chosenProduct: config.initialProduct ? productOption(config.initialProduct) : null,
    sourceId: config.fromId,
    sources: [],
    packaging: null,
    cargo: null,
    weightValue: '',
    pallets: '',
    dimensions: { width: '', depth: '', height: '' },
    sourceVerified: false,
    siteVerified: false,
    siteRequired: false,
    reasonText: '',
    note: '',
    mode: 'suggestions',
    search: '',
    results: null,
    rejected: [],
    showMore: false,
    selected: null,
    selectedSignature: '',
    stockSignature: '',
    lookupError: '',
    sourceError: '',
    productError: '',
    cargoError: '',
    notice: '',
    reviewError: '',
    requiresRefresh: false,
    blockers: [],
    reviewed: null,
    pendingCommand: null,
    confirmed: false,
    success: null,

    init() {
        this.loadProducts();
        if (this.productId) this.loadSource();
        this.$watch('cargoBasis', () => {
            this.sourceVerified = false;
            this.siteVerified = false;
            this.invalidateLocations();
            this.queueCargo();
        });
        this.$watch('signature', () => {
            this.cancel('preview');
            this.reviewed = null;
            this.confirmed = false;
            this.blockers = [];
            this.reviewError = '';
        });
    },
    destroy() {
        clearTimeout(this.productTimer);
        clearTimeout(this.cargoTimer);
        for (const field of fields) this.cancel(field);
    },
    cancel(key) {
        this.requests[key]?.abort();
        delete this.requests[key];
    },
    async request(key, route, data = null) {
        this.cancel(key);
        const controller = new AbortController();
        this.requests[key] = controller;
        try {
            const result = await readJson(route, controller.signal, data);
            return this.requests[key] === controller ? result : null;
        } catch (error) {
            if (error.name !== 'AbortError' && this.requests[key] === controller) throw error;
            return null;
        } finally {
            if (this.requests[key] === controller) delete this.requests[key];
        }
    },
    sourceUrl(filters) {
        const url = new URL(config.sources);
        for (const [key, value] of Object.entries(filters)) if (value !== '') url.searchParams.set(key, value);
        return url;
    },
    get productOptions() {
        if (!this.chosenProduct || this.productMatches.some(row => row.value === this.chosenProduct.value)) return this.productMatches;
        return [this.chosenProduct, ...this.productMatches];
    },
    get sourceOptions() {
        return this.sources.filter(row => row.movable_quantity > 0).map(row => ({
            value: String(row.location_id),
            label: row.code + (row.name === row.code ? '' : ' · ' + row.name),
            search: [row.code, row.name, this.position(row)].join(' '),
        }));
    },
    get sourceDetails() {
        return Object.fromEntries(this.sources.map(row => [row.location_id, [
            this.position(row), this.pieces(row.stored_quantity) + ' tárolva', this.pieces(row.movable_quantity) + ' áthelyezhető',
            row.load_requires_verification ? 'Terhelés ellenőrzendő' : '',
        ].filter(Boolean).join(' · ')]));
    },
    get warehouseName() { return this.allWarehouses.find(row => row.value === this.warehouseId)?.label ?? '—'; },
    get source() { return this.sources.find(row => String(row.location_id) === this.sourceId) ?? null; },
    get maxMovable() { return this.source?.movable_quantity ?? 0; },
    get amount() { return positiveInteger(this.quantity, this.maxMovable) ? Number(this.quantity) : null; },
    get cargoBasis() {
        return this.source && this.amount !== null ? JSON.stringify([this.source.source_revision, this.amount]) : '';
    },
    get identity() {
        return {
            warehouse_id: this.warehouseId, product_id: this.productId, from_location_id: this.sourceId,
            quantity: this.amount, source_revision: this.source?.source_revision,
        };
    },
    get measured() { return this.cargo?.cargo.load_source === 'measured'; },
    get physicalErrors() {
        if (this.measured && this.weightValue !== '' && weight(this.weightValue) === null) return 'Érvényes bruttó tömeget adj meg, legfeljebb három tizedesjeggyel.';
        if (this.measured && this.pallets !== '' && integer(this.pallets, 0, 1_000_000) === null) return 'Egész raklaphelyigényt adj meg. Raklap nélküli rakományhoz 0-t írj.';
        if (axes.some(axis => this.dimensions[axis] !== '' && integer(this.dimensions[axis], 1, 65_535) === null)) return 'A külső méreteket pozitív egész centiméterben add meg.';
        return '';
    },
    get physicalRequest() {
        return {
            ...this.identity,
            ...(this.measured ? { cargo_weight_kg: weight(this.weightValue), pallet_slots: integer(this.pallets, 0, 1_000_000) } : {}),
            dimensions_cm: Object.fromEntries(axes.filter(axis => this.dimensions[axis] !== '')
                .map(axis => [axis, integer(this.dimensions[axis], 1, 65_535)])),
        };
    },
    get signature() { return JSON.stringify(this.physicalRequest); },
    get refreshing() { return Boolean(this.requests.sources); },
    get loading() { return Boolean(this.requests.lookup); },
    get draftLocked() { return this.submitting || this.pendingCommand !== null; },
    get canLookUp() { return Boolean(this.cargoBasis && this.cargo?.basis === this.cargoBasis && !this.physicalErrors && !this.refreshing && !this.requests.preview && !this.draftLocked); },
    get canReview() {
        return this.canLookUp && !this.requiresRefresh && Boolean(this.selected) && !this.requests.preview
            && (!this.measured || (weight(this.weightValue) !== null && integer(this.pallets, 0, 1_000_000) !== null))
            && (!this.cargo?.cargo.source_verification_required || this.sourceVerified) && (!this.siteRequired || this.siteVerified);
    },
    get showTargetStock() { return Boolean(this.selected && this.productId && this.amount !== null); },
    get targetStockCaption() {
        const balance = this.selected?.product_stock?.bin;
        if (this.stockSignature !== this.signature || this.loading || this.refreshing || this.requiresRefresh || !balance) {
            return 'Új ellenőrzés szükséges.';
        }
        if (balance.before == null || balance.after == null) return 'Nem állapítható meg. Új ellenőrzés szükséges.';

        return stockCaption(balance);
    },
    get resultRows() { return this.results?.slice(0, this.showMore ? undefined : 6) ?? []; },
    get canSeeMore() { return (this.results?.length ?? 0) > 6; },
    get emptyMessage() {
        return this.mode === 'manual' ? 'A keresésre nem található célrekesz ebben a raktárban.' : 'A megadott rakományhoz nincs ajánlható rekesz.';
    },
    get nextStep() {
        if (this.requiresRefresh) return 'Frissítsd a forrás és a cél adatait, majd ellenőrizd újra az áthelyezést.';
        if (!this.warehouseId || !this.productId) return 'Válassz raktárat és terméket.';
        if (!this.source) return 'Válassz forrásrekeszt.';
        if (this.amount === null) return 'Adj meg a forrásból elérhető darabszámot.';
        if (!this.cargo || this.cargo.basis !== this.cargoBasis) return 'A forrástétel rakományadatainak ellenőrzése szükséges.';
        if (!this.selected) return 'Válassz célrekeszt.';
        if (this.measured && weight(this.weightValue) === null) return 'Add meg a mért bruttó tömeget.';
        if (this.measured && integer(this.pallets, 0, 1_000_000) === null) return 'Add meg a raklaphelyigényt. Raklap nélküli áruhoz adj meg 0-t.';
        if (this.cargo.cargo.source_verification_required && !this.sourceVerified) return 'Erősítsd meg a forrásterhelés helyszíni ellenőrzését.';
        if (this.siteRequired && !this.siteVerified) return 'Erősítsd meg a célrekesz fizikai elférését.';
        return '';
    },
    stockCaption,
    numberValue(value) { return value == null ? '—' : number.format(value); },
    pieces(value) { return value == null ? '—' : number.format(value) + ' db'; },
    mass(value) { return value == null ? 'Ismeretlen' : number.format(value / 1000) + ' kg'; },
    slots(value) { return value == null ? 'Ismeretlen' : (value === 0 ? '0 · Nem raklapos' : number.format(value) + ' raklaphely'); },
    position(location) { return [...new Set(Object.values(location.position ?? {}).filter(Boolean))].join(' · '); },
    locationStatus(status) { return statusNames[status] ?? 'Elérhetetlen'; },
    targetStatus(location) {
        if (location.status !== 'conditional') return this.locationStatus(location.status);
        const missing = location.checks?.missing ?? {};
        const geometry = ['cargo_dimensions', 'location_dimensions', 'remaining_physical_space'];
        return Object.keys(missing).some(key => !geometry.includes(key))
            ? 'Rakományadatok szükségesek' : 'Helyszíni ellenőrzés szükséges';
    },
    get targetCheck() {
        if (this.selectedSignature !== this.signature) return 'unknown';
        if (this.siteVerified && this.reviewed?.site_verification_required && this.reviewed.payload.site_verified
            && this.reviewed.location.status !== 'rejected') return 'site_verified';
        if (this.selected?.status === 'verified') return 'verified';
        return this.selected?.status === 'rejected' ? 'blocked' : 'unknown';
    },
    get sourceCheck() {
        return this.cargo?.basis === this.cargoBasis
            ? this.cargo.source_allocations.some(row => row.remaining_load_requires_verification) : null;
    },
    checks(values) { return Object.values(values ?? {}); },

    queueProducts(query) {
        this.productTerm = query.trim().slice(0, 100);
        clearTimeout(this.productTimer);
        this.cancel('products');
        this.productMatches = [];
        this.productTimer = setTimeout(() => this.loadProducts(), 160);
    },
    async loadProducts() {
        clearTimeout(this.productTimer);
        this.productError = '';
        try {
            const data = await this.request('products', this.sourceUrl({
                mode: 'products', warehouse_id: this.warehouseId, from_location_id: config.fromId,
                search: this.productTerm, per_page: 16,
            }));
            if (data) this.productMatches = data.products.data.map(productOption);
        } catch (error) { this.productError = error.message; }
    },
    async filterWarehouses() {
        if (config.warehouseId) return;
        this.cancel('warehouses');
        if (!this.productId) {
            this.warehouseOptions = this.allWarehouses;
            return;
        }
        this.warehouseOptions = this.allWarehouses.filter(row => row.value === this.warehouseId);
        try {
            const data = await this.request('warehouses', this.sourceUrl({ product_id: this.productId }));
            if (data) {
                this.warehouseOptions = data.warehouses.map(warehouseOption);
                if (this.warehouseId && !this.warehouseOptions.some(row => row.value === this.warehouseId)) {
                    this.notice = 'A kiválasztott raktárban ebből a termékből már nincs áthelyezhető rekeszkészlet.';
                    this.warehouseChanged('');
                }
            }
        } catch (error) { this.sourceError = error.message; }
    },
    warehouseChanged(value) {
        if (value === this.warehouseId) return;
        this.warehouseId = value;
        this.resetSource();
        this.productTerm = '';
        this.productMatches = [];
        this.loadProducts();
        if (value && this.productId) this.loadSource();
    },
    productChanged(value) {
        if (value === this.productId) return;
        this.chosenProduct = this.productOptions.find(row => row.value === value) ?? null;
        this.productId = value;
        this.notice = '';
        this.resetSource();
        this.filterWarehouses();
        if (value && this.warehouseId) this.loadSource();
    },
    resetSource() {
        this.cancel('sources');
        this.sourceError = '';
        this.sources = [];
        this.packaging = null;
        this.sourceChanged(config.fromId);
    },
    sourceChanged(value) {
        this.sourceId = value;
        this.usePieces();
        this.editQuantity('');
        this.selected = null;
        this.weightValue = '';
        this.pallets = '';
        this.dimensions = { width: '', depth: '', height: '' };
        this.requiresRefresh = false;
        this.clearIntentFeedback();
        this.invalidateLocations();
    },
    async loadSource() {
        this.sourceError = '';
        try {
            const data = await this.request('sources', this.sourceUrl({
                warehouse_id: this.warehouseId, product_id: this.productId, from_location_id: config.fromId,
            }));
            if (!data) return false;
            this.sources = config.fromId ? [data.source] : data.sources;
            const originalSource = config.fromId || this.sourceId;
            if (originalSource && !this.sourceOptions.some(row => row.value === originalSource)) {
                this.sourceId = '';
                this.notice = config.fromId
                    ? 'Ebből a rekeszből a kiválasztott termék már nem helyezhető át. Válassz másik terméket vagy forrásrekeszt.'
                    : 'A korábbi forrásrekeszből már nincs áthelyezhető készlet. Válassz másik forrásrekeszt.';
            } else if (config.fromId) {
                this.sourceId = config.fromId;
                this.notice = '';
            }
            if (this.boxMode && this.packaging?.pieces !== data.packaging?.pieces) this.usePieces();
            this.packaging = data.packaging;
            return true;
        } catch (error) { this.sourceError = error.message; }
        return false;
    },
    useMaximum() {
        this.usePieces();
        if (this.maxMovable) this.editQuantity(String(this.maxMovable));
    },
    queueCargo() {
        clearTimeout(this.cargoTimer);
        this.cancel('cargo');
        this.cargo = null;
        this.cargoError = '';
        if (this.cargoBasis) this.cargoTimer = setTimeout(() => this.loadCargo(), 130);
    },
    async loadCargo() {
        clearTimeout(this.cargoTimer);
        const basis = this.cargoBasis;
        if (!basis) return;
        this.cargoError = '';
        try {
            const data = await this.request('cargo', config.candidates, { ...this.identity, mode: 'cargo' });
            if (data && this.cargoBasis === basis) this.cargo = { ...data, basis };
        } catch (error) { this.handleReadError(error, 'cargoError'); }
    },
    invalidateLocations() {
        this.cancel('lookup');
        this.cancel('preview');
        this.results = null;
        this.rejected = [];
        this.lookupError = '';
        this.siteVerified = false;
        this.siteRequired = false;
        this.selectedSignature = '';
        this.stockSignature = '';
    },
    setMode(mode) {
        if (mode === this.mode) return;
        this.mode = mode;
        this.changeSearch();
        if (mode === 'manual') this.$nextTick(() => this.$refs.search?.focus());
    },
    changeSearch() {
        this.cancel('lookup');
        this.results = null;
        this.rejected = [];
        this.lookupError = '';
    },
    async loadLocations(onlySelected = false) {
        if (!this.canLookUp) return;
        this.lookupError = '';
        if (!onlySelected) {
            this.results = null;
            this.rejected = [];
            this.showMore = false;
        }
        const signature = this.signature;
        const targetId = this.selected?.location_id;
        const request = this.mode === 'manual' || onlySelected
            ? { mode: 'search', ...(onlySelected ? { to_location_id: targetId } : { search: this.search.trim() }) }
            : { mode: 'recommendations' };
        try {
            const data = await this.request('lookup', config.candidates, { ...this.physicalRequest, ...request });
            if (!data || signature !== this.signature) return;
            const rows = data.recommendations ?? data.locations ?? [];
            if (!onlySelected) {
                this.results = rows;
                this.rejected = data.rejected ?? [];
            }
            if (targetId !== undefined && this.selected?.location_id === targetId) {
                const refreshed = rows.find(row => row.location_id === targetId);
                if (onlySelected && refreshed) this.selected = refreshed;
                this.selected.product_stock = refreshed?.product_stock;
                this.stockSignature = refreshed?.product_stock ? signature : '';
            }
        } catch (error) { this.handleReadError(error, 'lookupError'); }
    },
    selectLocation(location) {
        if (location.status === 'rejected' || String(location.location_id) === this.sourceId) return;
        this.selected = location;
        this.reviewed = null;
        this.blockers = [];
        this.reviewError = '';
        this.siteVerified = false;
        this.siteRequired = false;
        this.selectedSignature = this.signature;
        this.stockSignature = this.signature;
        this.cancel('preview');
        this.$nextTick(() => this.$refs.selection?.focus({ preventScroll: true }));
    },
    handleReadError(error, field) {
        this[field] = this.checks(error.errors).flat().join(' ') || error.message;
        if (error.status === 409) this.requiresRefresh = true;
    },
    get reviewPayload() {
        return { ...this.physicalRequest, to_location_id: this.selected?.location_id,
            source_load_verified: this.sourceVerified, site_verified: this.siteVerified,
            reason: this.reasonText.trim() || null, note: this.note.trim() || null };
    },
    async review() {
        if (!this.canReview) return;
        this.reviewError = '';
        this.blockers = [];
        this.clearIntentFeedback();
        this.confirmed = false;
        this.stockSignature = '';
        this.cancel('lookup');
        const payload = this.reviewPayload;
        const basis = JSON.stringify(payload);
        try {
            const result = await this.request('preview', config.preview, payload);
            if (!result || basis !== JSON.stringify(this.reviewPayload)) return;
            this.selected = { ...this.selected, ...result.location, product_stock: result.location.product_stock };
            this.selectedSignature = this.signature;
            this.stockSignature = this.signature;
            this.siteRequired = result.site_verification_required;
            this.blockers = this.checks(result.blocking_reasons);
            this.reviewed = { ...result, payload, allowed: result.can_relocate };
            if (result.can_relocate) {
                this.$refs.confirmation.showModal();
                this.$nextTick(() => this.$refs.reviewTitle?.focus({ preventScroll: true }));
            }
        } catch (error) { this.handleReadError(error, 'reviewError'); }
    },
    closeReview() {
        if (this.submitting) return;
        this.$refs.confirmation.close();
        this.$nextTick(() => this.$refs[this.pendingCommand ? 'retryButton' : 'reviewButton']?.focus({ preventScroll: true }));
    },
    async save() {
        if (this.submitting) return;
        const command = this.pendingCommand ?? (this.confirmed && this.reviewed?.allowed ? {
            ...this.reviewed.payload, target_revision: this.reviewed.target_revision, confirmed: true,
        } : null);
        if (!command) return;
        const result = await this.sendIntent(config.store, 'POST', command);
        if (result) {
            this.pendingCommand = null;
            this.success = result.relocation;
            this.$refs.confirmation.close();
            this.$nextTick(() => this.$refs.success?.focus());
            return;
        }
        const status = this.submissionStatus;
        if (status === null || status < 400 || status >= 500 || (this.pendingCommand && [401, 419].includes(status))) {
            this.pendingCommand = command;
            return;
        }
        this.pendingCommand = null;
        this.reviewed = null;
        this.confirmed = false;
        this.closeReview();
        if ([409, 422].includes(status)) {
            this.requiresRefresh = true;
            if (status === 409) this.intentAttempt = null;
            await this.refresh();
        }
    },
    async refresh() {
        if (!this.productId || !this.warehouseId || this.refreshing) return;
        this.invalidateLocations();
        this.sourceVerified = false;
        this.reviewed = null;
        this.confirmed = false;
        if (!await this.loadSource()) return;
        this.requiresRefresh = false;
        if (this.cargoBasis) {
            await this.loadCargo();
            if (this.selected && this.source && this.canLookUp) await this.loadLocations(true);
        }
    },
}, Object.getOwnPropertyDescriptors(transferQuantity({ pieces: '', boxFieldId: 'relocation-quantity' })));
