import { operationKey } from './transfer-intent-submission';

const number = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const dateTime = new Intl.DateTimeFormat('hu-HU', { dateStyle: 'medium', timeStyle: 'short' });
const labels = {
    location_weight: 'Rekesz teherbírása', level_weight: 'Polcszint teherbírása', system_weight: 'Állvány teherbírása',
    pallet_space: 'Raklapférőhely', pallet_kind: 'Raklapos tárolás feltétele', category: 'Termékkategória', active: 'Rekesz elérhetősége',
};
const scopeLabels = {
    location_grams: 'Rekesz teherbírása', level_grams: 'Polcszint teherbírása', system_grams: 'Állvány teherbírása', slots: 'Rekesz raklapférőhelye',
};
const previewLabels = {
    location_weight_grams: scopeLabels.location_grams, level_weight_grams: scopeLabels.level_grams,
    system_weight_grams: scopeLabels.system_grams, pallet_slots: scopeLabels.slots,
};
const staleMessage = 'A tétel időközben megváltozott. Ellenőrizd az új mennyiséget, majd kérj új előnézetet és erősítsd meg a mérést.';
const unavailableMessage = 'A tétel már nem érhető el a munkalapon. A rögzített mérések az előzményekben megtalálhatók.';

export default (initial, api) => ({
    row: initial.placement,
    capacity: initial.capacity,
    canConfirm: initial.can_confirm,
    canRelocate: initial.can_relocate,
    measurementStarted: initial.placement.status === 'pending',
    weight: '',
    slots: '',
    note: '',
    preview: null,
    errors: {},
    notice: null,
    recorded: null,
    unavailable: false,
    requiresRefresh: false,
    checking: false,
    refreshing: false,
    submitting: false,
    confirmed: false,
    retry: null,
    submissionError: '',
    version: 0,
    previewRequest: null,

    get emptyCheck() { return !this.row.placement_present && this.row.status === 'discrepancy_open'; },
    get editable() { return !this.refreshing && !this.submitting && !this.retry; },
    get openDiscrepancy() { return this.row.status === 'discrepancy_open' && !this.unavailable && this.recorded?.result !== 'verified'; },
    get showForm() { return !this.unavailable && !this.recorded && this.row.status !== 'verified' && this.measurementStarted; },
    get critical() { return this.openDiscrepancy && this.row.severity === 'critical'; },
    get criticalPreview() { return this.preview?.severity === 'critical'; },
    get documentedLimits() {
        return Object.entries(this.row.discrepancy?.limits ?? {}).map(([key, value]) => ({ ...value, key, label: labels[key] }));
    },
    get canReview() {
        return this.canConfirm && this.preview && this.editable && !this.requiresRefresh
            && (this.preview.can_verify || this.preview.requires_physical_confirmation);
    },
    get stateLabel() {
        if (this.recorded?.result === 'verified') return 'Ellenőrzött';
        if (this.openDiscrepancy) return this.critical ? 'Kritikus terhelési eltérés' : 'Nyitott terhelési eltérés';

        return this.row.status === 'verified' ? 'Ellenőrzött' : 'Ellenőrzésre vár';
    },
    get locationLabel() {
        return Object.values(this.row.location.position).filter(Boolean).join(' · ') || this.row.location.name;
    },
    get generalErrors() {
        return Object.entries(this.errors).filter(([field]) => !['cargo_weight_kg', 'pallet_slots', 'note'].includes(field))
            .flatMap(([, messages]) => messages);
    },
    get previewChecks() {
        return Object.entries(this.preview?.checks ?? {}).filter(([key, check]) => labels[key] && (key !== 'active' || !check.passed))
            .map(([key, check]) => ({ ...check, key, label: labels[key] }));
    },
    get previewCapacity() {
        return Object.entries(this.preview?.capacity_comparison ?? {}).map(([key, value]) => ({
            ...value, key, label: previewLabels[key],
        }));
    },
    get currentCapacity() {
        return Object.entries(this.capacity?.scopes ?? {}).map(([key, value]) => ({
            ...value, key, label: scopeLabels[key], slots: key === 'slots',
        }));
    },

    mass(value) { return value == null ? 'Nincs adat' : number.format(value / 1000) + ' kg'; },
    pallet(value) { return value == null ? 'Nincs adat' : number.format(value) + ' raklaphely'; },
    difference(value, slots = false) {
        return value == null ? '—' : (value > 0 ? '+' : '') + (slots ? this.pallet(value) : this.mass(value));
    },
    measuredAt(value) { return value ? dateTime.format(new Date(value)) : ''; },
    ruleList(rules) { return (rules ?? []).map(key => labels[key]).filter(Boolean).join(' · '); },
    capacityValue(value, unit) { return unit === 'pallet_slots' ? this.pallet(value) : this.mass(value); },

    destroy() { this.previewRequest?.abort(); },

    invalidate() {
        this.version++;
        this.previewRequest?.abort();
        this.previewRequest = null;
        this.checking = false;
        this.preview = null;
        this.confirmed = false;
    },

    edit() {
        this.invalidate();
        this.errors = {};
        if (this.notice?.kind === 'error') this.notice = null;
    },

    draft() {
        const kg = this.weight.trim().replace(',', '.');
        const slots = this.slots.trim();
        if (!this.emptyCheck) {
            if (!/^(?:[0-9]+(?:[.][0-9]{1,3})?|[.][0-9]{1,3})$/.test(kg) || +kg <= 0 || +kg > 1000000) {
                this.errors.cargo_weight_kg = ['Add meg a teljes mért tömeget kilogrammban, legfeljebb három tizedesjeggyel.'];
            }
            if (!/^[0-9]+$/.test(slots) || +slots > 1000000) {
                this.errors.pallet_slots = ['Add meg a raklaphelyek számát. Ha nincs raklap, írj 0-t.'];
            }
        }

        return this.emptyCheck
            ? { revision: this.row.revision, cargo_weight_kg: 0, pallet_slots: 0, note: this.note.trim() || null }
            : { revision: this.row.revision, cargo_weight_kg: +kg, pallet_slots: +slots, note: this.note.trim() || null };
    },

    async request(url, body, signal) {
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal,
            headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const result = await response.json().catch(() => null);

        return { status: response.redirected ? 401 : response.status, result };
    },

    async check() {
        if (!this.editable || this.checking || this.requiresRefresh) return;
        this.invalidate();
        this.notice = null;
        this.errors = {};
        const draft = this.draft();
        if (Object.keys(this.errors).length) {
            this.invalidFields();
            return;
        }
        const version = this.version;
        const controller = this.previewRequest = new AbortController();
        this.checking = true;
        try {
            const { status, result } = await this.request(api.preview, draft, controller.signal);
            if (version !== this.version || this.$refs.dialog?.open) return;
            if (status === 200 && result?.measurement && result.placement?.revision === draft.revision) {
                this.preview = result;
                this.row = result.placement;
                this.$nextTick(() => this.$refs.previewTitle?.focus());
                return;
            }
            if (status === 409 || status === 404) {
                await this.changed();
            } else {
                this.readError(status, result);
            }
        } catch {
            if (version === this.version) this.notify('error', 'Az előnézet most nem tölthető be. Próbáld újra.');
        } finally {
            if (version === this.version) this.checking = false;
        }
    },

    invalidFields() {
        this.notify('error', 'Ellenőrizd a megjelölt mérési adatokat.', false);
        const field = ['cargo_weight_kg', 'pallet_slots', 'note'].find(name => this.errors[name]);
        this.$nextTick(() => (field ? this.$root.querySelector('[name="' + field + '"]') : this.$refs.notice)?.focus());
    },

    readError(status, result) {
        if (status === 422 && result?.errors) {
            this.errors = result.errors;
            this.invalidFields();
        } else if ([401, 419].includes(status)) {
            this.notify('error', 'A munkamenet lejárt. Jelentkezz be újra a folytatáshoz.');
        } else if (status === 403) {
            this.canConfirm = false;
            this.notify('error', 'Ehhez a művelethez nincs jogosultságod.');
        } else {
            this.notify('error', 'A mérés adatai most nem ellenőrizhetők. Próbáld újra.');
        }
    },

    openReview() {
        if (!this.canReview && !this.retry) return;
        this.confirmed = !!this.retry;
        this.submissionError = '';
        this.$refs.dialog.showModal();
        this.$nextTick(() => this.$refs.dialogTitle?.focus());
    },

    closeReview() {
        if (this.submitting) return;
        this.$refs.dialog.close();
        this.confirmed = false;
        this.$nextTick(() => this.$refs[this.retry ? 'retryButton' : 'openReview']?.focus());
    },

    async save() {
        if (this.submitting || !(this.retry || (this.confirmed && this.canReview))) return;
        const command = this.retry ?? {
            revision: this.preview.placement.revision,
            cargo_weight_kg: this.preview.measurement.weight_grams / 1000,
            pallet_slots: this.preview.measurement.pallet_slots,
            note: this.preview.measurement.note,
            measurement_confirmed: true,
            operation_key: operationKey(),
        };
        this.submitting = true;
        this.submissionError = '';
        let reply;
        try {
            reply = await this.request(api.store, command);
        } catch {
            reply = { status: 0, result: null };
        } finally {
            this.submitting = false;
        }
        const { status, result } = reply;
        const discrepancy = status === 422 && result?.discrepancy_open === true
            && result.verification?.result === 'rejected' && Number.isInteger(result.verification?.id);
        const verified = status === 201 && result?.verification?.result === 'verified' && Number.isInteger(result.verification?.id);
        if (discrepancy || verified) {
            this.retry = null;
            this.$refs.dialog.close();
            this.invalidate();
            this.recorded = result.verification;
            this.measurementStarted = false;
            this.capacity = null;
            this.errors = {};
            this.weight = this.slots = this.note = '';
            await this.refresh();
            const current = !this.requiresRefresh && !this.unavailable;
            // Closing an empty placement removes it from the live worksheet.
            const closedEmpty = this.unavailable && result.verification.quantity === 0;
            if (verified && ((current && this.row.status === 'verified') || closedEmpty)) {
                this.notify('success', 'A mérést rögzítettük és az ellenőrzést lezártuk.');
            } else {
                this.notify('logged', current && this.openDiscrepancy
                    ? 'A mérést rögzítettük. A terhelési eltérés továbbra is nyitott.'
                    : 'A mérést rögzítettük.');
            }
            return;
        }
        const expiredAfterUncertainSave = this.retry && [401, 419].includes(status);
        if (status >= 500 || status < 400 || expiredAfterUncertainSave) {
            this.retry = command;
            this.submissionError = expiredAfterUncertainSave
                ? 'A munkamenet lejárt. Jelentkezz be újra, majd próbáld újra ugyanazt a mérést.'
                : 'A mentés eredménye nem ellenőrizhető. Próbáld újra ugyanazt a mérést.';
            return;
        }

        this.retry = null;
        this.$refs.dialog.close();
        this.invalidate();
        if (status === 409 || status === 404) {
            await this.changed();
        } else {
            this.readError(status, result);
        }
    },

    async changed() {
        this.invalidate();
        this.requiresRefresh = true;
        this.notify('warning', staleMessage);
        await this.refresh();
    },

    async refresh() {
        if (this.refreshing) return;
        this.refreshing = true;
        try {
            const { status, result } = await this.request(api.show);
            if (status === 200 && result?.placement) {
                this.row = result.placement;
                // A replay acknowledges the old request. The fresh row decides which workflow is open now.
                if (this.recorded) {
                    this.recorded = null;
                    this.measurementStarted = this.row.status === 'pending';
                }
                this.capacity = result.capacity;
                this.canConfirm = result.can_confirm;
                this.canRelocate = result.can_relocate;
                this.requiresRefresh = false;
            } else if (status === 404) {
                this.capacity = null;
                this.unavailable = true;
                this.requiresRefresh = false;
                if (!this.recorded) this.notify('warning', unavailableMessage);
            } else {
                this.requiresRefresh = true;
            }
        } catch {
            this.requiresRefresh = true;
        } finally {
            this.refreshing = false;
        }
    },

    continueMeasurement() {
        this.invalidate();
        this.measurementStarted = true;
        this.recorded = null;
        this.notice = null;
        this.$nextTick(() => this.$refs[this.emptyCheck ? 'measureTitle' : 'weight']?.focus());
    },

    notify(kind, text, focus = true) {
        this.notice = { kind, text };
        if (focus) this.$nextTick(() => this.$refs.notice?.focus());
    },
});
