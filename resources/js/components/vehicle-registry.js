import intentSubmission from './transfer-intent-submission';

const displayNumber = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const textOrder = new Intl.Collator('hu-HU', { numeric: true });
const editNumber = new Intl.NumberFormat('hu-HU', { useGrouping: false, maximumFractionDigits: 3 });
const searchText = value => String(value ?? '').toLocaleLowerCase('hu').normalize('NFD').replace(/\p{M}/gu, '');

async function getJson(url, signal) {
    let response;
    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal,
        });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('A kapcsolat megszakadt. Próbáld meg újra.');
    }
    const result = await response.json().catch(() => null);
    if (response.ok && Array.isArray(result?.data)) return result;
    if (response.redirected || [401, 419].includes(response.status)) {
        throw new Error('A munkamenet lejárt. Jelentkezz be újra az adatok megtekintéséhez.');
    }
    throw new Error(result?.message || 'Az adatok betöltése sikertelen.');
}

export default ({ urls, canManage, sortColumns }) => ({
    ...intentSubmission(result => Boolean(result?.vehicle?.id)),
    vehicles: [],
    search: '',
    status: 'active',
    sortBy: 'name',
    sortDirection: 'ascending',
    loading: true,
    loadError: '',
    listRequest: null,
    notice: '',
    editor: null,
    transition: null,
    pending: null,
    needsRefresh: false,
    relatedVehicle: null,
    relatedStatus: 'all',
    related: null,
    relatedRequest: null,
    relatedError: '',

    init() { this.loadVehicles(); },
    destroy() {
        this.listRequest?.abort();
        this.listRequest = null;
        this.relatedRequest?.abort();
        this.relatedRequest = null;
    },

    get results() {
        const words = searchText(this.search).trim().split(/\s+/).filter(Boolean);
        const matches = this.vehicles.filter(vehicle => {
            if (this.status !== 'all' && (this.status === 'active') !== vehicle.active) return false;
            const text = searchText([vehicle.name, vehicle.registration_number].join(' '));
            return words.every(word => text.includes(word));
        });

        const key = this.sortBy;
        const direction = this.sortDirection === 'ascending' ? 1 : -1;
        return matches.sort((first, second) => {
            const left = key === 'active' ? Number(!first.active) : first[key];
            const right = key === 'active' ? Number(!second.active) : second[key];
            // Keep unknown values after known values in either direction.
            const missing = Number(left == null) - Number(right == null);
            if (missing) return missing;
            const order = left == null ? 0 : typeof left === 'number' ? left - right : textOrder.compare(left, right);
            return direction * order || textOrder.compare(first.name, second.name) || first.id - second.id;
        });
    },
    get sortLabel() { return `· ${sortColumns[this.sortBy]} ${this.sortArrow(this.sortBy)}`; },
    setSort(key) {
        this.sortDirection = this.sortBy === key && this.sortDirection === 'ascending' ? 'descending' : 'ascending';
        this.sortBy = key;
    },
    sortArrow(key) {
        if (this.sortBy !== key) return '↕';
        return this.sortDirection === 'ascending' ? '↑' : '↓';
    },
    closeRowMenus() {
        this.$root.querySelectorAll('[popover]:popover-open').forEach(menu => menu.hidePopover());
    },
    get commandLocked() { return this.submitting || this.pending !== null || this.needsRefresh; },
    get editorDeactivates() { return this.editor?.wasActive === true && !this.editor.active; },
    get canSaveEditor() {
        return Boolean(this.editor && !this.commandLocked && (!this.editorDeactivates || this.editor.inactivationConfirmed));
    },

    massLabel(grams) { return grams == null ? 'Nincs megadva' : displayNumber.format(grams / 1000) + ' kg'; },
    slotsLabel(slots) { return slots == null ? 'Nincs megadva' : displayNumber.format(slots) + ' hely'; },
    fieldError(key) { return this.fieldErrors[key]?.[0] ?? ''; },
    vehicleUrl(template, id) { return template.replace('__vehicle__', encodeURIComponent(id)); },

    async loadVehicles() {
        this.listRequest?.abort();
        const request = new AbortController();
        this.listRequest = request;
        this.loading = true;
        this.loadError = '';
        try {
            const result = await getJson(urls.index, request.signal);
            if (this.listRequest === request) this.vehicles = result.data;
        } catch (error) {
            if (this.listRequest === request) this.loadError = error.message;
        } finally {
            if (this.listRequest === request) {
                this.listRequest = null;
                this.loading = false;
            }
        }
    },

    openEditor(vehicle = null) {
        if (!canManage || this.commandLocked) return;
        this.resetIntentSubmission();
        this.editor = {
            id: vehicle?.id ?? null,
            version: vehicle?.version ?? null,
            name: vehicle?.name ?? '',
            registration: vehicle?.registration_number ?? '',
            payload: vehicle?.payload_capacity_grams == null ? '' : editNumber.format(vehicle.payload_capacity_grams / 1000),
            slots: vehicle?.pallet_slots_capacity == null ? '' : String(vehicle.pallet_slots_capacity),
            note: vehicle?.note ?? '',
            active: vehicle?.active ?? true,
            wasActive: vehicle?.active ?? null,
            reason: '',
            inactivationConfirmed: false,
        };
        this.$refs.editorDialog.showModal();
        this.$nextTick(() => this.$refs.nameInput.focus());
    },
    openTransition(vehicle) {
        if (!canManage || this.commandLocked) return;
        this.resetIntentSubmission();
        this.transition = { vehicle, reason: '' };
        this.$refs.transitionDialog.showModal();
        this.$nextTick(() => this.$refs.transitionCancel.focus());
    },
    closeCommand(dialog) {
        if (this.submitting || this.pending) return;
        dialog.close();
        this.editor = null;
        this.transition = null;
        this.needsRefresh = false;
        this.resetIntentSubmission();
    },

    async saveEditor() {
        if (!canManage || !this.canSaveEditor) return;
        const editor = this.editor;
        const payload = {
            name: editor.name.trim(),
            registration_number: editor.registration.trim() || null,
            payload_capacity_kg: editor.payload.trim().replace(',', '.') || null,
            pallet_slots_capacity: editor.slots.trim() || null,
            note: editor.note.trim() || null,
            active: editor.active,
        };
        if (editor.id !== null) payload.version = editor.version;
        if (this.editorDeactivates) payload.inactivation_reason = editor.reason.trim() || null;
        await this.submitCommand({
            dialog: 'editorDialog',
            url: editor.id === null ? urls.index : this.vehicleUrl(urls.update, editor.id),
            method: editor.id === null ? 'POST' : 'PATCH',
            payload,
            transition: editor.wasActive !== null && editor.wasActive !== editor.active,
        });
    },
    async saveTransition() {
        if (!canManage || this.commandLocked || !this.transition) return;
        const { vehicle, reason } = this.transition;
        const payload = { version: vehicle.version, active: !vehicle.active };
        if (vehicle.active) payload.inactivation_reason = reason.trim() || null;
        await this.submitCommand({
            dialog: 'transitionDialog',
            url: this.vehicleUrl(urls.update, vehicle.id),
            method: 'PATCH',
            payload,
            transition: true,
        });
    },
    async retryCommand() {
        if (canManage && this.pending && !this.submitting) await this.submitCommand(this.pending);
    },
    async submitCommand(command) {
        const result = await this.sendIntent(command.url, command.method, command.payload);
        this.pending = null;
        if (!result) {
            const status = this.submissionStatus;
            this.needsRefresh = [401, 403, 404, 409, 419].includes(status);
            if (status === null || status < 400 || status >= 500) this.pending = command;
            return;
        }
        const action = command.transition
            ? (result.vehicle.active ? 'Jármű újraaktiválva' : 'Jármű inaktiválva')
            : (command.method === 'POST' ? 'Jármű létrehozva' : 'Jármű adatai mentve');
        this.notice = action + ' · ' + result.vehicle.name;
        this.closeCommand(this.$refs[command.dialog]);
        this.$refs.searchInput.focus();
        await this.loadVehicles();
    },
    async refreshAfterConflict(dialog) {
        this.closeCommand(dialog);
        this.$refs.searchInput.focus();
        await this.loadVehicles();
    },

    openRelated(vehicle) {
        this.relatedVehicle = vehicle;
        this.relatedStatus = 'all';
        this.related = null;
        this.$refs.relatedDialog.showModal();
        this.$nextTick(() => this.$refs.relatedFilter.focus());
        this.loadRelated();
    },
    closeRelated() {
        this.relatedRequest?.abort();
        this.relatedRequest = null;
        this.$refs.relatedDialog.close();
        this.relatedVehicle = null;
    },
    async loadRelated(page = 1) {
        this.relatedRequest?.abort();
        const request = new AbortController();
        this.relatedRequest = request;
        this.relatedError = '';
        const target = this.vehicleUrl(urls.shipments, this.relatedVehicle.id);
        const url = new URL(target, window.location.href);
        url.searchParams.set('status', this.relatedStatus);
        url.searchParams.set('page', page);
        try {
            const result = await getJson(url, request.signal);
            if (this.relatedRequest === request) this.related = result;
        } catch (error) {
            if (this.relatedRequest === request) this.relatedError = error.message;
        } finally {
            if (this.relatedRequest === request) this.relatedRequest = null;
        }
    },
});
