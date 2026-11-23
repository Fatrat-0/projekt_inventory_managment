import intentSubmission from './transfer-intent-submission';
import { budapestLocalMinute, planningFieldError, planningInstant } from './planning-time';
import shipmentPlanCargo from './shipment-plan-cargo';
import shipmentPlanVehicle from './shipment-plan-vehicle';

const quantity = new Intl.NumberFormat('hu-HU');
const mass = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const date = new Intl.DateTimeFormat('hu-HU', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Budapest',
});
const departureExpired = blocker => blocker.code === 'planned_departure_expired';
const sameOrder = (left, right) => left.length === right.length && left.every((stop, index) => stop.id === right[index].id);

async function readJson(url, signal) {
    let response;
    try {
        response = await fetch(url, { signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('A kapcsolat megszakadt. Próbáld meg újra.');
    }
    const data = await response.json().catch(() => null);
    if (response.ok && data) return data;
    const error = new Error(data?.message || 'Az adatok betöltése sikertelen. Próbáld meg újra.');
    error.status = response.redirected ? 401 : response.status;
    throw error;
}

export default ({ shipmentId, originId, canManage, canOverrideDedicated, urls }) => Object.defineProperties({
    ...intentSubmission(result => result?.shipment?.id === shipmentId),
    ...shipmentPlanCargo(urls),
    detail: null,
    routeStops: [],
    loadingOrder: [],
    readiness: null,
    cargo: null,
    dispatchState: null,
    dispatchConfirmed: false,
    dispatchNote: '',
    departure: '',
    departureEditing: false,
    departureBadInput: false,
    checkingDispatch: false,
    loading: true,
    dataError: '',
    accessLost: false,
    needsRefresh: false,
    notice: '',
    canManage,
    canOverrideDedicated,
    search: '',
    eligibleRows: [],
    eligiblePage: null,
    eligibleError: '',
    eligibleLoading: false,
    selected: [],
    groupingReason: '',
    groupingConfirmed: false,
    detachReason: '',
    detachTarget: null,
    pendingCommand: null,
    detailRequest: null,
    eligibleRequest: null,
    destroyed: false,

    init() {
        this.$watch('search', () => this.invalidateEligible());
        this.refreshPlan();
    },

    destroy() {
        this.destroyed = true;
        this.detailRequest?.abort();
        this.eligibleRequest?.abort();
        this.destroyVehicles?.();
    },

    get assignments() { return this.detail?.assignments ?? []; },
    get stops() { return this.detail?.stops ?? []; },
    get saving() { return this.submitting; },
    get busy() { return this.loading || this.saving || this.unconfirmed || this.checkingDispatch; },
    get unconfirmed() { return this.pendingCommand !== null; },
    get canEdit() { return this.canManage && this.detail?.shipment.status === 'draft' && !this.accessLost; },
    get mayChange() { return this.canEdit && !this.busy && !this.needsRefresh && !this.dataError; },
    get orderChanged() { return !sameOrder(this.routeStops, this.stops); },
    get departureChanged() {
        return this.departureEditing && (this.departureBadInput
            || this.departure !== budapestLocalMinute(this.detail?.shipment.planned_departure_at));
    },
    get needsGrouping() {
        return this.selected.length > 0 && this.selected.length + this.assignments.length > 1
            && (this.selected.some(row => row.shipping_preference === 'dedicated_requested')
                || this.assignments.some(row => row.intent.shipping_preference === 'dedicated_requested'));
    },
    get preparedCount() { return this.readiness?.intents.filter(row => row.stock_ready).length ?? 0; },
    get draft() { return this.detail?.shipment.status === 'draft'; },
    get canDispatch() {
        return this.draft && this.readiness?.can_dispatch === true && this.readiness?.has_dispatch_permission === true
            && this.cargo?.confirmation?.status === 'confirmed' && !this.orderChanged && !this.departureChanged
            && !this.busy && !this.needsRefresh && !this.dataError && !this.accessLost;
    },
    get departureBlocker() { return this.readiness?.dispatch_blockers?.find(departureExpired); },
    get dispatchRecord() { return this.dispatchState?.dispatch ?? null; },
    get transitItems() { return this.dispatchState?.transit_items ?? []; },
    get dispatchStops() { return this.dispatchRecord?.snapshot?.stops ?? []; },
    get capacityRows() {
        const capacity = this.readiness?.capacity;
        return [
            { key: 'weight_grams', name: 'Bruttó tömeg', ...capacity?.weight_grams },
            { key: 'pallet_slots', name: 'Raklaphely', ...capacity?.pallet_slots },
        ];
    },

    intentUrl(id) { return urls.intent.replace('__intent__', encodeURIComponent(id)); },
    taskUrl(id) { return urls.task.replace('__intent__', encodeURIComponent(id)); },
    canOpenTask(row) { return Boolean(row?.picking_task_id && row.can_view_picking_task !== false); },
    prepFor(id) { return this.readiness?.intents.find(row => row.transfer_intent_id === id); },
    formatQuantity(value) { return value == null ? '—' : `${quantity.format(value)} db`; },
    formatMass(value) { return value == null ? '—' : `${mass.format(value / 1000)} kg`; },
    formatSlots(value) { return value == null ? '—' : `${quantity.format(value)} hely`; },
    capacityValue(row, value) { return row.key === 'weight_grams' ? this.formatMass(value) : this.formatSlots(value); },
    vehicleName(vehicle) {
        return vehicle ? [vehicle.name, vehicle.registration_number].filter(Boolean).join(' · ') : 'Nincs kijelölve';
    },
    transitIntent(row) {
        const historical = this.dispatchRecord?.snapshot?.intents?.find(intent => intent.transfer_intent_id === row.transfer_intent_id);
        const planned = this.assignments.find(assignment => assignment.transfer_intent_id === row.transfer_intent_id)?.intent;
        return { ...planned, ...historical };
    },
    transitStop(row) { return this.dispatchStops.find(stop => stop.id === row.shipment_stop_id); },
    formatDate(value) {
        const parsed = value ? new Date(value) : null;
        return parsed && Number.isFinite(parsed.getTime()) ? date.format(parsed) : '—';
    },

    formatRequestedArrival(intent) {
        const day = intent.requested_arrival_date;
        return day ? day.split('-').join('. ') + '.' : this.formatDate(intent.requested_arrival_at);
    },

    formatPlannedDeparture(value) {
        const local = budapestLocalMinute(value);
        if (!local) return 'Nincs megadva';
        const [day, time] = local.split('T');
        return day.split('-').join('. ') + '. ' + time;
    },

    invalidateEligible() {
        this.eligibleRequest?.abort();
        this.eligibleRequest = null;
        this.eligibleRows = [];
        this.eligiblePage = null;
        this.eligibleError = '';
        this.eligibleLoading = this.canEdit && !this.accessLost;
    },

    async changeSearch() { await this.loadEligible(1); },

    async loadEligible(page = 1) {
        if (!this.canEdit || this.needsRefresh || this.destroyed) return;
        this.invalidateEligible();
        const controller = new AbortController();
        this.eligibleRequest = controller;
        const search = this.search.trim();
        const url = new URL(urls.eligible, window.location.href);
        url.searchParams.set('origin_warehouse_id', originId);
        url.searchParams.set('shipment_id', shipmentId);
        url.searchParams.set('per_page', 12);
        url.searchParams.set('page', page);
        if (search) url.searchParams.set('search', search);
        try {
            const result = await readJson(url, controller.signal);
            if (this.eligibleRequest !== controller || this.destroyed || search !== this.search.trim()) return;
            if (page > result.last_page) return this.loadEligible(result.last_page);
            this.eligibleRows = result.data;
            this.eligiblePage = result;
        } catch (error) {
            if (this.eligibleRequest !== controller || this.destroyed) return;
            if (error.status === 403) {
                this.canManage = false;
                this.canOverrideDedicated = false;
                this.eligibleError = error.message;
            } else {
                this.eligibleError = error.message;
            }
        } finally {
            if (this.eligibleRequest === controller) {
                this.eligibleRequest = null;
                this.eligibleLoading = false;
            }
        }
    },

    async fetchPlan(signal) {
        const read = () => Promise.all([urls.detail, urls.loading, urls.readiness, urls.cargo, urls.dispatch].map(url => readJson(url, signal)));
        const coherent = ([plan, order, readiness, cargo, dispatch]) => plan.shipment.id === shipmentId
            && readiness.shipment_id === shipmentId && cargo.shipment_id === shipmentId && dispatch.shipment.id === shipmentId
            && [order.version, readiness.version, cargo.version, dispatch.shipment.version].every(version => version === plan.shipment.version)
            && (plan.shipment.status !== 'draft' || (cargo.basis_revision === readiness.basis_revision
                && cargo.vehicle?.version === readiness.vehicle?.version));
        let result = await read();
        if (!coherent(result)) result = await read();
        if (!coherent(result)) throw new Error('A fuvarterv közben megváltozott. Töltsd be újra az aktuális adatokat.');
        return result;
    },

    async refreshPlan({ discardOrder = false } = {}) {
        if (this.submitting || this.unconfirmed || this.destroyed) return;
        this.detailRequest?.abort();
        const controller = new AbortController();
        this.detailRequest = controller;
        this.loading = true;
        this.dataError = '';
        this.invalidateEligible();
        const retainOrder = !discardOrder && this.orderChanged;
        const previousOrder = this.routeStops.map(stop => stop.id);
        const recovering = this.needsRefresh;
        this.closeDialog(this.$refs.groupingDialog);
        this.closeDialog(this.$refs.detachDialog);
        this.closeDialog(this.$refs.dispatchDialog);
        this.closeDialog(this.$refs.itemCargoDialog);
        this.closeDialog(this.$refs.totalCargoDialog);
        this.closeVehiclePicker?.();
        try {
            const [plan, order, readiness, cargo, dispatch] = await this.fetchPlan(controller.signal);
            if (this.detailRequest !== controller || this.destroyed) return;
            this.detail = plan;
            const historicalStops = dispatch.dispatch?.snapshot?.stops;
            this.loadingOrder = historicalStops ? [...historicalStops].sort((first, second) => first.loading_position - second.loading_position) : order.data;
            this.readiness = readiness;
            this.cargo = cargo;
            this.dispatchState = dispatch;
            this.canManage = plan.can_manage ?? this.canManage;
            this.canOverrideDedicated = plan.can_override_dedicated ?? this.canOverrideDedicated;
            this.accessLost = false;
            this.routeStops = historicalStops ?? (retainOrder ? this.restoreOrder(plan.stops, previousOrder) : [...plan.stops]);
            this.selected = this.selected.filter(row => !plan.assignments.some(assignment => assignment.transfer_intent_id === row.id));
            this.groupingConfirmed = false;
            this.needsRefresh = false;
            this.resetIntentSubmission();
            if (recovering) this.notice = this.dispatchRecord ? 'A fuvar aktuális feladási adatai betöltődtek.' : 'A fuvarterv frissült. Ellenőrizd az adatokat mentés előtt.';
            if (this.canEdit) await this.loadEligible();
            if (recovering && this.detailRequest === controller) this.$nextTick(() => this.$refs.planHeading?.focus());
        } catch (error) {
            if (this.detailRequest !== controller || this.destroyed) return;
            this.dataError = error.message;
            if ([401, 403, 404].includes(error.status)) {
                this.accessLost = true;
                this.detail = null;
                this.routeStops = [];
                this.loadingOrder = [];
                this.readiness = null;
                this.cargo = null;
                this.dispatchState = null;
                this.canManage = false;
            }
        } finally {
            if (this.detailRequest === controller) {
                this.detailRequest = null;
                this.loading = false;
                if (!this.canEdit || this.needsRefresh || this.dataError) this.eligibleLoading = false;
            }
        }
    },

    restoreOrder(stops, previousOrder) {
        const byId = new Map(stops.map(stop => [stop.id, stop]));
        const ordered = previousOrder.filter(id => byId.has(id)).map(id => byId.get(id));
        return [...ordered, ...stops.filter(stop => !previousOrder.includes(stop.id))];
    },

    isSelected(id) { return this.selected.some(row => row.id === id); },
    toggleIntent(row) {
        if (!this.mayChange || this.eligibleLoading) return;
        if (this.isSelected(row.id)) return this.removeSelection(row.id);
        if (this.selected.length < 1000) this.selected.push(row);
        this.groupingConfirmed = false;
    },
    removeSelection(id) {
        if (!this.mayChange) return;
        this.selected = this.selected.filter(row => row.id !== id);
        this.groupingConfirmed = false;
    },

    openDialog(dialog, focus) {
        this.clearIntentFeedback();
        this.notice = '';
        dialog.showModal();
        if (focus) this.$nextTick(() => focus.focus());
    },
    closeDialog(dialog) {
        if (!this.submitting && dialog?.open) dialog.close();
    },
    async attachSelected() {
        if (!this.mayChange || !this.selected.length) return;
        if (this.needsGrouping) {
            if (this.canOverrideDedicated) this.openDialog(this.$refs.groupingDialog, this.$refs.groupingReasonInput);
            return;
        }
        await this.confirmAttach();
    },
    async confirmAttach() {
        if (this.unconfirmed) return this.retryCommand();
        if (!this.mayChange || !this.selected.length) return;
        const data = { intent_ids: this.selected.map(row => row.id) };
        if (this.needsGrouping) {
            if (!this.canOverrideDedicated || !this.groupingConfirmed || !this.groupingReason.trim()) return;
            data.grouping_reason = this.groupingReason.trim();
        }
        await this.saveCommand('attach', urls.attach, 'POST', data);
    },
    openDetach(row) {
        if (!this.mayChange) return;
        if (this.detachTarget?.id !== row.id) this.detachReason = '';
        this.detachTarget = row;
        this.openDialog(this.$refs.detachDialog);
    },
    async confirmDetach() {
        if (this.unconfirmed) return this.retryCommand();
        if (!this.mayChange || !this.detachTarget) return;
        const url = urls.detach.replace('__intent__', encodeURIComponent(this.detachTarget.transfer_intent_id));
        await this.saveCommand('detach', url, 'DELETE', { reason: this.detachReason.trim() || null });
    },

    moveStop(index, step) {
        const target = index + step;
        if (!this.mayChange || target < 0 || target >= this.routeStops.length) return;
        const order = [...this.routeStops];
        [order[index], order[target]] = [order[target], order[index]];
        this.routeStops = order;
    },
    resetOrder() {
        if (this.mayChange) this.routeStops = [...this.stops];
    },
    async saveOrder() {
        if (!this.mayChange || !this.orderChanged) return;
        await this.saveCommand('reorder', urls.stops, 'PATCH', { stop_ids: this.routeStops.map(stop => stop.id) });
    },

    openDepartureEditor() {
        if (!this.mayChange) return;
        this.closeDialog(this.$refs.dispatchDialog);
        this.clearIntentFeedback();
        this.departure = budapestLocalMinute(this.detail.shipment.planned_departure_at);
        this.departureBadInput = false;
        this.departureEditing = true;
        this.$nextTick(() => this.$refs.departureInput?.focus());
    },
    closeDepartureEditor() {
        this.departureEditing = false;
        if (this.submissionStatus === 422 && this.fieldErrors.planned_departure_at) this.clearIntentFeedback();
        else delete this.fieldErrors.planned_departure_at;
        this.$nextTick(() => this.$refs.departureEditButton?.focus());
    },
    async saveDeparture() {
        if (!this.mayChange || !this.departureEditing) return;
        const instant = planningInstant(this.departure);
        const error = planningFieldError(this.$refs.departureInput, this.departure, instant);
        if (error) {
            this.fieldErrors.planned_departure_at = [error];
            this.$refs.departureInput.focus();
            return;
        }
        if (!await this.saveCommand('updateDeparture', urls.departure, 'PATCH', { planned_departure_at: instant })
            && this.fieldErrors.planned_departure_at) {
            this.$refs.departureInput.focus();
        }
    },
    async openDispatch() {
        if (!this.canDispatch) return;
        const plan = this.detail;
        this.checkingDispatch = true;
        try {
            const readiness = await readJson(urls.readiness);
            if (this.destroyed || this.detail !== plan || this.loading) return;
            if (readiness.shipment_id !== shipmentId || readiness.version !== plan.shipment.version
                || readiness.basis_revision !== this.cargo?.basis_revision || readiness.vehicle?.version !== this.cargo?.vehicle?.version) {
                this.needsRefresh = true;
                return;
            }
            this.readiness = readiness;
        } catch (error) {
            if (!this.destroyed && this.detail === plan) this.dataError = error.message;
            return;
        } finally {
            this.checkingDispatch = false;
        }
        if (!this.canDispatch) return;
        this.dispatchConfirmed = false;
        this.dispatchNote = '';
        this.openDialog(this.$refs.dispatchDialog);
    },
    async confirmDispatch() {
        if (!this.canDispatch || !this.dispatchConfirmed || !this.$refs.dispatchDialog?.open) return;
        await this.saveCommand('dispatch', urls.dispatch, 'POST', { confirmed: true, note: this.dispatchNote.trim() || null }, 'dispatchDialog');
    },

    async saveCommand(action, url, method, data, dialog = null) {
        if (this.submitting || this.unconfirmed || !this.detail) return false;
        const command = { action, url, method, data: { version: this.detail.shipment.version, ...data }, dialog };
        return this.runCommand(command);
    },
    async retryCommand() {
        if (this.pendingCommand && !this.submitting) await this.runCommand(this.pendingCommand);
    },
    async runCommand(command) {
        this.notice = '';
        const result = await this.sendIntent(command.url, command.method, command.data);
        if (this.destroyed) return;
        this.pendingCommand = null;
        if (!result || (command.action === 'dispatch' && (!result.dispatch?.id || result.shipment.status !== 'in_transit'))) {
            if (result) this.submissionError = 'A feladás eredménye nem ellenőrizhető. Próbáld meg ismét ugyanazokkal az adatokkal.';
            const expired = command.action === 'dispatch' && this.conflictBlockers.find(departureExpired);
            if (expired) {
                this.readiness = { ...this.readiness, can_dispatch: false, dispatch_blockers: this.conflictBlockers,
                    minimum_planned_departure_at: expired.minimum_planned_departure_at };
            } else if (this.conflict) {
                this.needsRefresh = true;
            }
            if ([401, 403, 404, 419].includes(this.submissionStatus)) this.needsRefresh = true;
            if (this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500) this.pendingCommand = command;
            return false;
        }

        const { action } = command;
        if (action === 'attach') {
            this.selected = [];
            this.groupingReason = '';
            this.groupingConfirmed = false;
            this.notice = `${command.data.intent_ids.length} igényt hozzáadtál a fuvarhoz.`;
        } else if (action === 'detach') {
            this.detachTarget = null;
            this.detachReason = '';
            this.notice = 'Az igényt leválasztottad a fuvarról.';
        } else if (action === 'reorder') {
            this.notice = 'A megállók sorrendje elmentve.';
        } else if (action === 'updateDeparture') {
            this.departureEditing = false;
            this.notice = 'A tervezett indulás időpontja frissült.';
        } else if (action === 'dispatch') {
            this.detail = { ...this.detail, shipment: { ...this.detail.shipment, ...result.shipment }, can_manage: false };
            this.dispatchState = result;
            this.notice = 'A fuvar feladásra került.';
        } else {
            this.notice = { selectVehicle: 'A jármű hozzárendelése mentve.', saveItem: 'A tétel rakományadatai mentve.', confirmCargo: 'A teljes rakomány adatai megerősítve.' }[action] ?? '';
        }
        if (command.dialog) this.closeDialog(this.$refs[command.dialog]);
        this.resetIntentSubmission();
        await this.refreshPlan({ discardOrder: action === 'reorder' });
        if (action === 'updateDeparture' && this.canEdit) this.$nextTick(() => this.$refs.departureEditButton?.focus());
        if (action === 'dispatch' && !this.accessLost) this.$nextTick(() => this.$refs.transitHeading?.focus());
        return true;
    },
}, Object.getOwnPropertyDescriptors(shipmentPlanVehicle({ urls, readJson })));
