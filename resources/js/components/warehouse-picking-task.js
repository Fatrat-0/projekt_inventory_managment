import intentSubmission from './transfer-intent-submission';

const number = new Intl.NumberFormat('hu-HU');
const date = new Intl.DateTimeFormat('hu-HU', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Budapest',
});
const sourceKey = source => String(source.location_id ?? 'unassigned');
const positiveInteger = value => /^\d+$/.test(String(value)) && Number.isSafeInteger(Number(value)) && Number(value) > 0;
const searchText = value => String(value ?? '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLocaleLowerCase('hu');

export default ({ snapshot, urls, product, statuses }) => ({
    ...intentSubmission(),
    snapshot,
    statuses,
    product,
    sources: [],
    suggestion: [],
    draft: [],
    adviceNotice: '',
    unassignedFound: false,
    unassignedDescription: '',
    mode: 'suggestions',
    search: '',
    loading: false,
    refreshing: false,
    refreshError: '',
    sourceError: '',
    needsRefresh: false,
    committed: false,
    unconfirmedPick: false,
    accessError: '',
    consistent: true,
    message: '',
    sourceRequest: null,
    refreshRequest: null,
    destroyed: false,

    init() {
        if (this.canWork) this.loadSources();
    },

    destroy() {
        this.destroyed = true;
        this.sourceRequest?.abort();
        this.refreshRequest?.abort();
    },

    get task() { return this.snapshot.task; },
    get taskStatus() { return this.statuses[this.task.status] ?? this.task.status; },
    get canWork() {
        return this.snapshot.can_execute && ['pending', 'in_progress'].includes(this.task.status);
    },
    get remaining() { return Math.max(0, this.task.expected_quantity - this.task.staged_quantity); },
    get total() { return this.draft.reduce((sum, row) => sum + (positiveInteger(row.quantity) ? Number(row.quantity) : 0), 0); },
    get progress() { return this.task.expected_quantity ? 100 * this.task.staged_quantity / this.task.expected_quantity : 0; },
    get sourcesReady() { return !this.loading && !this.sourceError && !this.needsRefresh; },
    get unassignedSource() { return this.sources.find(source => source.source_type === 'unassigned'); },
    get canUseUnassigned() {
        return this.consistent && this.unassignedSource?.active && this.unassignedSource.available_quantity > 0;
    },
    get hasUsableBin() {
        return this.consistent && this.sources.some(source => source.source_type === 'location' && source.active && source.available_quantity > 0);
    },
    get hasUnassignedDraft() { return this.draft.some(row => row.source_type === 'unassigned'); },
    get suggestedSources() {
        const bins = new Map(this.sources.filter(source => source.source_type === 'location' && source.active)
            .map(source => [sourceKey(source), source]));
        return this.suggestion.filter(row => row.source_type === 'location' && positiveInteger(row.quantity)
            && row.quantity <= (bins.get(sourceKey(row))?.available_quantity ?? 0))
            .map(row => ({ ...bins.get(sourceKey(row)), quantity: row.quantity }));
    },
    get suggestedQuantity() { return this.suggestedSources.reduce((sum, source) => sum + source.quantity, 0); },
    get canApplySuggestion() {
        return this.sourcesReady && this.consistent && !this.draftLocked && !this.draft.length && this.suggestedSources.length > 0;
    },
    get matchingSources() {
        const words = searchText(this.search).trim().split(/\s+/u);
        return this.sources.filter(source => source.source_type === 'location' && source.stored_quantity > 0 && words.every(word => [
            this.sourceName(source), source.name, source.position_label, source.zone, source.system, product,
        ].some(text => searchText(text).includes(word))));
    },
    get draftError() {
        if (this.total > this.remaining) return 'A megadott mennyiség meghaladja a feladat hátralévő mennyiségét.';
        return this.draft.some(row => this.rowError(row)) ? 'Ellenőrizd a megadott forráshelyeket és darabszámokat.' : '';
    },
    get canConfirm() {
        return this.canWork && this.consistent && this.total > 0 && !this.draftError && !this.submitting
            && !this.loading && !this.refreshing && !this.needsRefresh && !this.sourceError;
    },
    get canRecordPick() {
        return this.canConfirm && (!this.hasUnassignedDraft || (this.unassignedFound && this.unassignedDescription.trim() !== ''));
    },
    get draftLocked() { return this.submitting || this.unconfirmedPick; },

    quantity(value) { return value == null ? '—' : `${number.format(value)} db`; },
    key(source) { return sourceKey(source); },
    sourceName(source) {
        return source.source_type === 'unassigned' ? 'Tárhelyhez nem rendelt készlet' : (source.code || source.name || 'Tárolóhely');
    },
    historicalSource(row) {
        return this.sourceName({ ...row, code: row.location_code, name: row.location_name });
    },
    eventDate(value) { return value ? date.format(new Date(value)) : '—'; },
    async readResponse(url, signal) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal, headers: { Accept: 'application/json' } })
            .catch(() => { throw new Error('A kapcsolat megszakadt. Próbáld újra a frissítést.'); });
        if (response.status === 401 || response.status === 419 || response.redirected) {
            const error = new Error('A munkamenet lejárt. Jelentkezz be újra, majd nyisd meg a feladatot.');
            error.accessLost = true;
            throw error;
        }
        const data = await response.json().catch(() => null);
        if (response.status === 409 || response.status === 403 || response.status === 404) {
            const error = new Error(data?.message || 'A feladat aktuális állapota már nem érhető el.');
            error.requiresRefresh = true;
            error.accessLost = response.status !== 409;
            throw error;
        }
        if (!response.ok || !data) throw new Error('Az adatok betöltése sikertelen. Próbáld meg újra.');
        return data;
    },

    stopSources() {
        this.sourceRequest?.abort();
        this.sourceRequest = null;
        this.loading = false;
        this.sources = [];
        this.suggestion = [];
        this.sourceError = '';
    },

    revokeAccess(message) {
        this.stopSources();
        this.accessError = message;
        this.snapshot = { version: this.snapshot.version, task: { status: '' }, can_execute: false, allocations: [] };
        this.draft = [];
        this.resetUnassignedConfirmation();
        this.adviceNotice = '';
        this.unconfirmedPick = false;
        this.message = '';
        this.needsRefresh = false;
        this.resetIntentSubmission();
        if (this.$refs.confirmation?.open) this.$refs.confirmation.close();
        this.$nextTick(() => this.$refs.accessNotice.focus());
    },

    async loadSources() {
        if (!this.canWork || this.needsRefresh || this.draftLocked) return;
        this.stopSources();
        const request = new AbortController();
        this.sourceRequest = request;
        this.loading = true;
        const { version } = this.snapshot;
        const taskId = this.task.id;
        try {
            const options = await this.readResponse(urls.recommendations, request.signal);
            if (this.sourceRequest !== request || this.destroyed) return;
            if (options.version !== version || options.task_id !== taskId) {
                this.needsRefresh = true;
                this.sourceError = 'A feladat időközben megváltozott. Frissítsd a feladatot és a készletet.';
                return;
            }
            this.sources = options.sources;
            this.suggestion = options.suggestion;
            this.consistent = options.consistent;
            this.snapshot.free_quantity = options.free_quantity;
        } catch (error) {
            if (this.sourceRequest !== request || this.destroyed) return;
            if (error.accessLost) return this.revokeAccess(error.message);
            this.needsRefresh = Boolean(error.requiresRefresh);
            this.sourceError = error.message;
        } finally {
            if (this.sourceRequest === request) {
                this.sourceRequest = null;
                this.loading = false;
            }
        }
    },

    sourceSelected(source) { return this.draft.some(row => sourceKey(row) === sourceKey(source)); },
    addSource(source, quantity = '', focus = true) {
        if (!this.canWork || this.draftLocked || !source?.active || !(source.available_quantity > 0) || this.sourceSelected(source)) return;
        this.adviceNotice = '';
        this.draft.push({ location_id: source.location_id, source_type: source.source_type, label: this.sourceName(source), quantity: String(quantity) });
        if (focus) this.$nextTick(() => document.getElementById(`pick-quantity-${sourceKey(source)}`)?.focus());
    },
    applySuggestion() {
        if (!this.canApplySuggestion) return;
        const sources = this.suggestedSources;
        sources.forEach(source => this.addSource(source, source.quantity, false));
        this.adviceNotice = `${number.format(this.draft.length)} forrás hozzáadva, összesen ${this.quantity(this.total)}.`;
        this.$nextTick(() => this.$refs.draftHeading.focus());
    },
    openManual(focusUnassigned = false) {
        this.mode = 'manual';
        this.$nextTick(() => (focusUnassigned ? this.$refs.unassignedHeading : this.$refs.sourceSearch)?.focus());
    },
    resetUnassignedConfirmation() {
        this.unassignedFound = false;
        this.unassignedDescription = '';
    },
    removeSource(row) {
        if (this.draftLocked) return;
        this.draft = this.draft.filter(item => sourceKey(item) !== sourceKey(row));
        this.adviceNotice = '';
        if (row.source_type === 'unassigned') this.resetUnassignedConfirmation();
        this.$nextTick(() => this.$refs.draftHeading.focus());
    },
    rowError(row) {
        if (!positiveInteger(row.quantity)) return 'Adj meg pozitív egész darabszámot.';
        if (this.loading || this.needsRefresh || this.sourceError) return '';
        const current = this.sources.find(source => sourceKey(source) === sourceKey(row));
        return !current?.active || Number(row.quantity) > current.available_quantity
            ? 'Innen jelenleg legfeljebb ' + this.quantity(current?.available_quantity ?? 0) + ' tárolható ki.' : '';
    },

    openConfirmation() {
        if (this.canConfirm) this.$refs.confirmation.showModal();
    },
    closeConfirmation() {
        if (!this.submitting) this.$refs.confirmation.close();
    },
    async recordPick() {
        if (!this.canRecordPick) return;
        const allocations = this.draft.map(({ source_type, location_id, quantity }) => ({
            source_type, location_id, quantity: Number(quantity),
        })).sort((a, b) => (a.location_id ?? 0) - (b.location_id ?? 0));
        const payload = {
            version: this.snapshot.version, quantity: this.total, source_allocations: allocations,
        };
        if (this.hasUnassignedDraft) {
            payload.unassigned_confirmation = { found: true, description: this.unassignedDescription.trim() };
        }
        const result = await this.sendIntent(urls.picks, 'POST', payload);
        if (this.destroyed) return;
        if (!result) {
            if ([401, 403, 404, 419].includes(this.submissionStatus)) {
                this.revokeAccess(this.submissionError);
                return;
            }
            this.unconfirmedPick = this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500;
            if (this.conflict || Object.keys(this.fieldErrors).some(field => !field.startsWith('unassigned_confirmation'))) {
                this.needsRefresh = true;
                this.$nextTick(() => this.$refs.refreshInDialog.focus());
            }
            return;
        }

        this.unconfirmedPick = false;
        this.committed = true;
        this.needsRefresh = true;
        this.draft = [];
        this.resetUnassignedConfirmation();
        this.adviceNotice = '';
        this.message = `${this.quantity(result.task.staged_quantity)} összekészítve a feladathoz.`;
        this.resetIntentSubmission();
        this.$refs.confirmation.close();
        this.$nextTick(() => this.$refs.progressHeading.focus());
        window.dispatchEvent(new CustomEvent('warehouse-tasks:changed'));
        await this.refreshTask();
    },

    async refreshTask() {
        if (this.draftLocked || this.refreshing || this.accessError) return;
        if (this.$refs.confirmation?.open) {
            this.$refs.confirmation.close();
            this.$nextTick(() => this.$refs.progressHeading.focus());
        }
        this.stopSources();
        const request = new AbortController();
        this.refreshRequest = request;
        this.refreshing = true;
        this.needsRefresh = true;
        this.refreshError = '';
        try {
            const current = await this.readResponse(urls.status, request.signal);
            if (this.refreshRequest !== request || this.destroyed) return;
            if (current.task?.id !== this.task.id) throw new Error('A kitárolási feladat már nem érhető el.');
            this.snapshot = current;
            this.needsRefresh = false;
            this.adviceNotice = '';
            this.unassignedFound = false;
            this.clearIntentFeedback();
            if (!this.committed) this.message = 'A feladat és a készlet adatai frissültek.';
            this.committed = false;
            await this.loadSources();
        } catch (error) {
            if (this.refreshRequest !== request || this.destroyed) return;
            if (error.accessLost) return this.revokeAccess(error.message);
            this.refreshError = this.committed ? 'A rögzítés sikerült. Az adatok betöltéséhez próbáld újra a frissítést.' : error.message;
        } finally {
            if (this.refreshRequest === request) {
                this.refreshing = false;
                this.refreshRequest = null;
            }
        }
    },
});
