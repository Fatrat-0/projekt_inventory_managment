import intentSubmission from './transfer-intent-submission';
import readJson from './warehouse-json';
import stockCaption from './putaway-stock-caption';
import queueAdvice from './putaway-advice';
import putawayResultRows, { hasHiddenPutawayRows } from './putaway-result-rows';

const number = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const date = new Intl.DateTimeFormat('hu-HU', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Budapest',
});
const axes = ['width', 'depth', 'height'];
const geometryChecks = ['cargo_dimensions', 'location_dimensions', 'remaining_physical_space'];
const labels = { pending: 'Betárolásra vár', partial: 'Részben betárolva', completed: 'Teljesen betárolva' };
const statuses = { verified: 'Ellenőrzött', conditional: 'Feltételes', rejected: 'Nem alkalmas' };
const receiptItem = response => response?.item ?? response?.batch;

function integer(value, min, max) {
    const text = String(value).trim();
    const parsed = Number(text);
    return /^\d+$/.test(text) && Number.isSafeInteger(parsed) && parsed >= min && parsed <= max ? parsed : null;
}

function weightGrams(value) {
    const text = value.trim().replace(',', '.');
    const parsed = Math.round(Number(text) * 1000);
    return /^\d+(\.\d{1,3})?$/.test(text) && parsed >= 1 && parsed <= 1_000_000_000 ? parsed : null;
}

export default ({ detail, history, urls }) => ({
    ...intentSubmission(result => receiptItem(result)?.id === detail.item.id && Boolean(result?.event?.id && result?.placement?.id)),
    detail,
    history,
    quantity: String(detail.item.pending_putaway_quantity || ''),
    weight: '',
    usePackaging: false,
    pallets: '',
    dimensions: { width: '', depth: '', height: '' },
    note: '',
    mode: 'suggestions',
    search: '',
    selected: null,
    selectedSignature: '',
    results: null,
    rejected: [],
    showMore: false,
    lookupError: '',
    lookupRequest: null,
    adviceTimer: null,
    lastAdviceKey: '',
    selectedError: '',
    lookupSequence: 0,
    loading: false,
    historyLoading: false,
    historyError: '',
    historySequence: 0,
    refreshing: false,
    needsRefresh: false,
    accessError: '',
    pendingCommand: null,
    confirmation: false,
    siteVerified: false,
    success: null,
    previewRequest: null,
    previewing: false,
    reviewed: null,
    confirmationOpen: false,

    init() {
        this.$watch('adviceKey', () => this.queueAdvice());
        this.queueAdvice();
    },
    destroy() {
        clearTimeout(this.adviceTimer);
        this.lookupRequest?.abort();
        this.previewRequest?.abort();
    },

    get item() { return this.detail.item; },
    get draftLocked() { return this.submitting || this.pendingCommand !== null; },
    get eligible() { return this.item.can_putaway && !this.accessError; },
    get quantityValue() { return integer(this.quantity, 1, this.item.pending_putaway_quantity); },
    get palletValue() { return integer(this.pallets, 0, 1_000_000); },
    get weightValue() { return this.usePackaging ? this.packagingWeight?.total ?? null : weightGrams(this.weight); },
    get packagingWeight() {
        const p = this.detail.packaging;
        const quantity = this.quantityValue;
        const size = Number(p?.pieces_per_package ?? 0);
        const weight = Number(p?.gross_weight_grams ?? 0);
        if (!quantity || size < 1 || weight < 1 || quantity % size !== 0) return null;
        const count = quantity / size;
        const total = count * weight;
        return total <= 1_000_000_000 ? { count, weight, total } : null;
    },
    get formula() {
        const batch = this.packagingWeight;
        return batch ? `${number.format(batch.count)} csomag × ${this.mass(batch.weight)} = ${this.mass(batch.total)}` : '';
    },
    get quantityError() {
        return this.quantityValue === null
            ? `Adj meg egész darabszámot 1 és ${number.format(this.item.pending_putaway_quantity)} között.` : '';
    },
    get weightError() {
        return !this.usePackaging && this.weight.trim() && this.weightValue === null
            ? 'Adj meg 0,001 és 1 000 000 kg közötti tömeget, legfeljebb három tizedesjeggyel.' : '';
    },
    get palletError() {
        return this.pallets.trim() && this.palletValue === null
            ? 'Adj meg egész számot 0 és 1 000 000 között.' : '';
    },
    get dimensionError() {
        return axes.some(axis => this.dimensions[axis].trim() && integer(this.dimensions[axis], 1, 65_535) === null)
            ? 'A méreteket 1 és 65 535 közötti egész centiméterben add meg.' : '';
    },
    get cargo() {
        const cargo = { quantity: this.quantityValue, pallet_slots: this.palletValue };
        const dimensions = Object.fromEntries(axes.map(axis => [axis, integer(this.dimensions[axis], 1, 65_535)]));
        if (axes.some(axis => dimensions[axis] !== null)) cargo.dimensions_cm = dimensions;
        if (this.usePackaging && this.packagingWeight) {
            cargo.weight_source = 'calculated';
            cargo.cargo_weight_packaging = this.detail.packaging.revision;
            cargo.packaging_confirmed = true;
        } else if (this.weightValue !== null) {
            cargo.cargo_weight_kg = this.weightValue / 1000;
        }
        return cargo;
    },
    get signature() { return JSON.stringify(this.cargo); },
    get canLookUp() {
        return this.eligible && !this.draftLocked && !this.refreshing && !this.needsRefresh && !this.previewing
            && !this.quantityError && !this.weightError && !this.palletError && !this.dimensionError;
    },
    get canSearchLocations() { return this.canLookUp && !this.confirmationOpen; },
    get requiresSiteCheck() {
        const missing = this.selected?.checks?.missing ?? {};
        return geometryChecks.some(check => Boolean(missing[check]));
    },
    get canReview() {
        return this.canSearchLocations && this.weightValue !== null && this.palletValue !== null
            && this.selectedCurrent && this.selected.status !== 'rejected';
    },
    get canSave() {
        const review = this.reviewed;
        return this.confirmationOpen && this.eligible && !this.draftLocked && !this.needsRefresh && review
            && review.signature === this.signature && review.command.version === this.item.version
            && review.command.to_location_id === this.selected?.location_id && review.command.note === (this.note.trim() || null)
            && this.confirmation && (!review.requiresSiteCheck || this.siteVerified);
    },
    get adviceKey() {
        if (!this.canSearchLocations) return '';
        const search = this.mode === 'manual' ? this.search.trim() : '';
        return JSON.stringify([this.mode, this.item.version, this.signature, search]);
    },
    queueAdvice,
    get selectedCurrent() {
        return Boolean(this.selected && !this.needsRefresh && !this.accessError && this.selectedSignature === this.signature);
    },
    get lookupHint() {
        if (this.needsRefresh) return 'Frissítsd a tételt az aktuális ajánlásokhoz.';
        if (this.quantityError || this.weightError || this.palletError || this.dimensionError) return 'Ellenőrizd a mennyiséget és a megadott rakományadatokat.';
        return '';
    },
    get visibleLimit() { return this.mode === 'manual' ? 8 : 3; },
    get resultRows() { return putawayResultRows(this); },
    get canSeeMore() { return hasHiddenPutawayRows(this); },
    get emptyMessage() {
        if (this.mode === 'manual' && this.search.trim()) return 'A keresésre nem található rekesz ebben a célraktárban.';
        return this.rejected.length
            ? 'A megadott részlethez nincs ajánlható rekesz. Az elutasítások okát lent ellenőrizheted, vagy kereshetsz kézzel.'
            : 'Ehhez a célraktárhoz még nincs elérhető rekesz.';
    },
    get nextStep() {
        if (this.needsRefresh) return 'Töltsd be az aktuális tételt, majd ellenőrizd újra a rekeszt.';
        if (this.quantityError) return 'Ellenőrizd a betárolandó darabszámot.';
        if (this.weightValue === null) return 'Add meg vagy erősítsd meg a rakomány tömegét.';
        if (this.palletValue === null) return 'Add meg a raklaphelyigényt. Raklap nélküli áruhoz adj meg 0-t.';
        if (this.dimensionError) return 'Ellenőrizd a rakomány méreteit.';
        if (!this.selected) return 'Kérj ajánlást vagy válassz célrekeszt.';
        if (!this.selectedCurrent) return 'Ellenőrizd a kijelölt rekeszt az aktuális rakományadatokkal.';
        if (this.selected.status === 'rejected') return 'Válassz alkalmas célrekeszt.';
        if (this.requiresSiteCheck) return 'A rögzítéshez helyszíni elférés-ellenőrzés szükséges.';
        return '';
    },

    stockCaption,
    numberValue(value) { return value == null ? '—' : number.format(value); },
    pieces(value) { return value == null ? '—' : `${number.format(value)} db`; },
    mass(value) { return value == null ? 'Ismeretlen' : `${number.format(value / 1000)} kg`; },
    slots(value) { return value == null ? 'Ismeretlen' : (value === 0 ? '0 · Nem raklapos' : `${number.format(value)} raklaphely`); },
    timestamp(value) { return value ? date.format(new Date(value)) : '—'; },
    itemStatus(status) { return labels[status] ?? 'Betárolás'; },
    locationStatus(status) { return statuses[status] ?? 'Elérhetetlen'; },
    position(location) {
        return [...new Set(Object.values(location.position ?? {}).filter(Boolean))].join(' · ');
    },
    checks(checks) { return Object.values(checks ?? {}); },

    stopLookup() {
        clearTimeout(this.adviceTimer);
        this.lookupRequest?.abort();
        this.lookupSequence++;
        this.loading = false;
    },
    clearSelection() {
        this.invalidatePreview();
        this.selected = null;
    },
    invalidatePreview() {
        this.previewRequest?.abort();
        this.previewRequest = null;
        this.previewing = false;
        this.selectedSignature = '';
        this.reviewed = null;
        this.confirmation = false;
        this.siteVerified = false;
    },
    changeCargo() {
        if (!this.packagingWeight) this.usePackaging = false;
        this.stopLookup();
        this.invalidatePreview();
        this.lastAdviceKey = '';
        this.selectedError = '';
        this.results = null;
        this.rejected = [];
        this.lookupError = '';
        this.clearIntentFeedback();
        this.$nextTick(() => this.queueAdvice());
    },
    setMode(mode) {
        if (this.draftLocked || mode === this.mode) return;
        this.stopLookup();
        this.mode = mode;
        this.lastAdviceKey = '';
        this.results = null;
        this.rejected = [];
        this.lookupError = '';
        this.showMore = false;
        this.queueAdvice();
        if (mode === 'manual') this.$nextTick(() => {
            const search = this.$refs.search;
            if (this.mode === 'manual' && search?.isConnected) search.focus();
        });
    },
    async loadLocations(onlySelected = false) {
        if (!this.canSearchLocations || this.loading) return;
        this.stopLookup();
        if (!onlySelected) {
            this.lastAdviceKey = this.adviceKey;
            this.results = null;
            this.rejected = [];
            this.showMore = false;
        }
        this.lookupError = '';
        this.loading = true;

        const mode = this.mode;
        const manual = mode === 'manual';
        const search = this.search.trim();
        const signature = this.signature;
        const version = this.item.version;
        const targetId = this.selected?.location_id;
        const cargo = this.cargo;
        const controller = new AbortController();
        const sequence = this.lookupSequence;
        this.lookupRequest = controller;
        const matchesRequest = () => this.canSearchLocations && this.lookupSequence === sequence && this.signature === signature
            && this.item.version === version && this.mode === mode && (onlySelected || !manual || this.search.trim() === search);
        const isCurrentTarget = () => matchesRequest() && Boolean(targetId) && this.selected?.location_id === targetId;
        if (targetId && (onlySelected || !manual)) {
            this.selectedSignature = '';
            this.selectedError = '';
        }
        const read = async (url, payload) => {
            const response = await readJson(url, controller.signal, payload);
            if (!matchesRequest()) return null;
            if (receiptItem(response)?.id !== this.item.id || receiptItem(response)?.version !== version) {
                this.needsRefresh = true;
                this.lookupError = 'A tétel időközben megváltozott. Töltsd be az aktuális állapotát.';
                return null;
            }
            return response;
        };
        try {
            const result = await read(manual || onlySelected ? urls.locations : urls.recommendations, {
                ...cargo, ...(onlySelected ? { to_location_id: targetId } : (manual && search ? { search } : {})),
            });
            if (!result || !matchesRequest()) return;
            const rows = (manual || onlySelected ? result.locations : result.recommendations) ?? [];
            if (!onlySelected) {
                this.results = rows;
                this.rejected = manual ? [] : (result.rejected ?? []);
            }
            if (!isCurrentTarget()) return;

            let match = [...rows, ...(result.rejected ?? [])].find(row => row.location_id === targetId);
            if (!match && !onlySelected && (!manual || !this.selectedCurrent)) {
                const exact = await read(urls.locations, { ...cargo, to_location_id: targetId });
                if (!exact || !isCurrentTarget()) return;
                match = exact.locations?.find(row => row.location_id === targetId);
            }
            if (match) {
                this.selected = match;
                this.selectedSignature = signature;
                this.selectedError = '';
            } else if (!this.selectedCurrent) {
                this.selectedError = 'A kiválasztott rekesz már nem érhető el.';
            }
        } catch (error) {
            if (!matchesRequest() || error.name === 'AbortError') return;
            this.needsRefresh = error.status === 409;
            if ([401, 403, 404, 419].includes(error.status)) this.accessError = error.message;
            this.lookupError = error.message;
            if (isCurrentTarget() && !this.selectedCurrent) this.selectedError = error.message;
        } finally {
            if (this.lookupSequence === sequence) {
                this.lookupRequest = null;
                this.loading = false;
                if (onlySelected && !this.lookupError) this.queueAdvice();
            }
        }
    },
    changeSearch() {
        if (this.mode !== 'manual') return;
        this.stopLookup();
        this.results = null;
        this.lookupError = '';
        this.lastAdviceKey = '';
        this.queueAdvice();
    },
    selectLocation(location) {
        if (!this.canLookUp || this.loading || !['verified', 'conditional'].includes(location.status)) return;
        this.clearIntentFeedback();
        this.clearSelection();
        this.selectedError = '';
        this.selected = location;
        this.selectedSignature = this.signature;
    },

    async openConfirmation() {
        if (!this.canReview) return;
        if (this.loading) this.lastAdviceKey = '';
        this.stopLookup();
        this.clearIntentFeedback();
        this.invalidatePreview();
        const controller = new AbortController();
        this.previewRequest = controller;
        this.previewing = true;
        const signature = this.signature;
        const version = this.item.version;
        const locationId = this.selected.location_id;
        const note = this.note.trim() || null;
        const current = () => this.previewRequest === controller && this.signature === signature
            && this.item.version === version && this.selected?.location_id === locationId
            && (this.note.trim() || null) === note;

        try {
            const payload = { ...this.cargo, to_location_id: locationId };
            const result = await readJson(urls.preview ?? urls.locations, controller.signal,
                urls.preview ? { ...payload, version } : payload);
            if (!current()) return;
            const latest = receiptItem(result);
            if (latest?.id !== this.item.id || latest?.version !== version) {
                this.needsRefresh = true;
                this.submissionError = 'A tétel időközben megváltozott. Töltsd be az aktuális állapotát.';
                return;
            }
            const location = urls.preview ? result.location : result.locations?.find(row => row.location_id === locationId);
            if (!location) {
                this.selectedError = 'A kiválasztott rekesz már nem érhető el.';
                return;
            }

            if (location.status !== this.selected.status) {
                this.results = null;
                this.rejected = [];
            }
            this.selected = location;
            this.selectedError = '';
            this.selectedSignature = signature;
            const reasons = urls.preview ? result.blocking_reasons : { ...location.checks.failed, ...location.checks.missing };
            const blockers = Object.entries(reasons ?? {}).filter(([rule]) => !geometryChecks.includes(rule));
            if (blockers.length) {
                this.submissionError = 'A kiválasztott rekeszhez a betárolás nem véglegesíthető.';
                this.fieldErrors = Object.fromEntries(blockers.map(([rule, reason]) => [rule, [reason]]));
                return;
            }
            if (urls.preview && !result.can_putaway && !result.site_verification_required) return;
            this.reviewed = {
                signature, command: { ...payload, version, note }, location, cargo: result.cargo,
                remaining: latest.pending_putaway_quantity - payload.quantity,
                requiresSiteCheck: geometryChecks.some(rule => Boolean(location.checks.missing?.[rule])),
            };
            this.confirmationOpen = true;
            this.$refs.confirmation.showModal();
        } catch (error) {
            if (!current() || error.name === 'AbortError') return;
            this.needsRefresh = error.status === 409;
            if ([401, 403, 404, 419].includes(error.status)) this.accessError = error.message;
            this.submissionError = error.message;
        } finally {
            if (this.previewRequest === controller) {
                this.previewRequest = null;
                this.previewing = false;
            }
        }
    },
    closeConfirmation() {
        if (!this.submitting && this.$refs.confirmation.open) this.$refs.confirmation.close();
    },
    async submit() {
        if (!this.canSave) return;
        const command = {
            ...this.reviewed.command, confirmed: true, site_verified: this.reviewed.requiresSiteCheck && this.siteVerified,
        };
        await this.runCommand(command);
    },
    async retry() {
        if (this.pendingCommand && !this.submitting) await this.runCommand(this.pendingCommand);
    },
    async runCommand(command) {
        if (this.submitting) return;
        this.success = null;
        const result = await this.sendIntent(urls.store, 'POST', command);
        if (!result) {
            const status = this.submissionStatus;
            if (status === null || status < 400 || status >= 500) {
                this.pendingCommand = command;
                return;
            }
            this.pendingCommand = null;
            if (status === 409) this.needsRefresh = true;
            if ([401, 403, 404, 419].includes(status)) this.accessError = this.submissionError;
            if ([409, 422].includes(status)) {
                this.stopLookup();
                this.invalidatePreview();
                this.results = null;
                this.rejected = [];
            }
            this.closeConfirmation();
            this.$nextTick(() => this.$refs.feedback?.focus());
            return;
        }

        this.pendingCommand = null;
        this.success = { ...result.placement, location_view_url: result.location_view_url };
        this.detail.item = { ...this.item, ...receiptItem(result) };
        this.quantity = String(this.item.pending_putaway_quantity || '');
        this.weight = '';
        this.usePackaging = false;
        this.pallets = '';
        this.dimensions = { width: '', depth: '', height: '' };
        this.note = '';
        this.clearSelection();
        this.changeCargo();
        this.resetIntentSubmission();
        this.closeConfirmation();
        this.$nextTick(() => this.$refs.success?.focus({ preventScroll: true }));
        window.dispatchEvent(new CustomEvent('warehouse-tasks:changed'));
        this.loadHistory();
        await this.refresh(true);
    },
    async refresh(quiet = false) {
        if (this.refreshing || this.draftLocked) return;
        this.refreshing = true;
        this.stopLookup();
        if (!quiet) {
            this.invalidatePreview();
            this.lastAdviceKey = '';
            this.results = null;
            this.rejected = [];
            this.lookupError = '';
        }
        try {
            const latest = await readJson(urls.detail);
            if (receiptItem(latest)?.id !== this.item.id) throw new Error('A betárolási tétel nem érhető el.');
            if (latest.packaging?.revision !== this.detail.packaging?.revision) this.usePackaging = false;
            this.detail = { ...latest, item: receiptItem(latest) };
            this.needsRefresh = false;
            this.resetIntentSubmission();
            if (quiet) this.quantity = String(this.item.pending_putaway_quantity || '');
            else this.loadHistory();
        } catch (error) {
            this.needsRefresh = true;
            if ([401, 403, 404, 419].includes(error.status)) this.accessError = error.message;
            this.submissionError = error.message;
        } finally {
            this.refreshing = false;
        }
    },
    async loadHistory(page = 1) {
        const sequence = ++this.historySequence;
        this.historyLoading = true;
        this.historyError = '';
        try {
            const url = new URL(urls.history, window.location.href);
            url.searchParams.set('page', page);
            url.searchParams.set('per_page', this.history.per_page);
            const history = await readJson(url);
            if (this.historySequence === sequence) this.history = history;
        } catch (error) {
            if (this.historySequence === sequence) this.historyError = error.message;
        } finally {
            if (this.historySequence === sequence) this.historyLoading = false;
        }
    },
});
