import transferQuantity from './transfer-quantity';
import intentSubmission, { operationKey } from './transfer-intent-submission';
import { planningFieldError, planningInstant } from './planning-time';

const draftFields = ['product', 'source', 'destination', 'priority', 'shippingPreference', 'arrival', 'arrivalDate',
    'arrivalDateOnly', 'reason', 'note', 'quantityDraft', 'lastValidQuantity', 'boxMode'];

export default initial => {
    const amount = transferQuantity({ pieces: '', boxCount: '', boxFieldId: 'intent-box-count' });

    return Object.defineProperties(amount, Object.getOwnPropertyDescriptors({
        ...intentSubmission(),
        endpoint: initial.endpoint,
        detailUrl: initial.detailUrl,
        productNames: initial.productNames,
        warehouses: initial.warehouses,
        requestWarehouseIds: initial.requestWarehouseIds,
        product: '',
        source: '',
        destination: '',
        priority: 'normal',
        shippingPreference: 'groupable',
        arrival: '',
        arrivalDate: '',
        arrivalDateOnly: false,
        reason: '',
        note: '',
        submitted: false,
        pending: null,
        recoveredIntentId: null,
        storageUnavailable: false,

        init() {
            this.restoreSubmission();
            this.$watch('draftSignature', () => {
                if (this.pending || this.recoveredIntentId) this.persistSubmission();
            });
        },

        get draftData() { return Object.fromEntries(draftFields.map(field => [field, this[field]])); },
        get draftSignature() { return JSON.stringify(this.draftData); },
        get visibleArrival() { return this.arrivalDateOnly ? this.arrivalDate : this.arrival; },
        get uncertain() { return Boolean(this.pending) && !this.submitting; },

        restoreSubmission() {
            if (this.submitting || this.storageUnavailable) return;
            try {
                const stored = JSON.parse(sessionStorage.getItem(initial.recoveryKey));
                if (!stored?.pending && !stored?.recoveredIntentId) {
                    if (this.pending || this.recoveredIntentId) window.location.reload();
                    return;
                }
                for (const field of draftFields) {
                    if (typeof stored.draft?.[field] === typeof this[field]) this[field] = stored.draft[field];
                }
                this.pending = stored.pending ?? null;
                this.recoveredIntentId = stored.recoveredIntentId ?? null;
                this.submitted = this.recoveredIntentId !== null;
            } catch {
                this.storageUnavailable = true;
            }
        },

        persistSubmission() {
            try {
                if (this.pending || this.recoveredIntentId) {
                    sessionStorage.setItem(initial.recoveryKey, JSON.stringify({
                        pending: this.pending, recoveredIntentId: this.recoveredIntentId, draft: this.draftData,
                    }));
                } else {
                    sessionStorage.removeItem(initial.recoveryKey);
                }
            } catch {
                this.storageUnavailable = true;
            }
        },

        intentDetailUrl(id) { return this.detailUrl.replace('__intent__', String(id)); },

        startSeparateIntent() {
            this.recoveredIntentId = null;
            this.submitted = false;
            this.resetIntentSubmission();
            this.persistSubmission();
        },

        get packaging() { return initial.packagings[this.product]; },
        get productName() { return this.productNames[this.product] ?? '–'; },
        get sourceOptions() { return this.warehouseOptions(this.destination); },
        get destinationOptions() { return this.warehouseOptions(this.source); },
        get priorityLabel() { return { normal: 'Normál', urgent: 'Sürgős', critical: 'Kritikus' }[this.priority]; },

        warehouseName(id) {
            return this.warehouses.find(warehouse => warehouse.value === id)?.label ?? '–';
        },

        warehouseOptions(otherId) {
            const otherIsLocal = this.requestWarehouseIds.includes(otherId);
            return this.warehouses.filter(({ value }) => value !== otherId
                && (!otherId || otherIsLocal || this.requestWarehouseIds.includes(value))).map(({ value }) => value);
        },

        chooseProduct(value) {
            if (this.product !== value) this.usePieces();
            this.product = value;
            this.clearFieldError('product_id');
        },

        chooseWarehouse(field, value) {
            this[field] = value;
            this.clearFieldError('from_warehouse_id');
            this.clearFieldError('to_warehouse_id');
        },

        clearFieldError(field) {
            delete this.fieldErrors[field];
            if (!Object.keys(this.fieldErrors).length && !this.conflict && this.submissionError === 'Ellenőrizd a megjelölt adatokat.') {
                this.submissionError = '';
            }
        },

        async submitIntent() {
            if (this.submitting || this.submitted) return;
            if (this.pending) return this.completeSubmission(this.pending, true);
            if (this.conflict) return;

            const errors = {};
            if (!this.product) errors.product_id = ['Válassz terméket.'];
            if (!this.source) errors.from_warehouse_id = ['Válassz forrásraktárt.'];
            if (!this.destination) errors.to_warehouse_id = ['Válassz célraktárt.'];
            else if (this.source && !this.destinationOptions.includes(this.destination)) {
                errors.to_warehouse_id = ['Válassz másik célraktárt. Az egyik raktárhoz igénylési jogosultság szükséges.'];
            }
            if (!this.quantityValid) errors.quantity = [this.quantityInputError || 'Add meg a kért darabszámot.'];

            const arrival = this.arrivalDateOnly ? this.arrivalDate || null : planningInstant(this.arrival);
            const arrivalField = this.arrivalDateOnly ? this.$refs.arrivalDate : this.$refs.arrival;
            const arrivalError = planningFieldError(arrivalField, this.visibleArrival, arrival);
            if (arrivalError) errors.requested_arrival_at = [arrivalError];

            if (Object.keys(errors).length) {
                this.clearIntentFeedback();
                this.fieldErrors = errors;
                this.submissionError = 'Ellenőrizd a megjelölt adatokat.';
                this.$nextTick(() => this.$refs.errorNotice?.focus());
                return;
            }

            this.pending = {
                key: operationKey(),
                draftSignature: this.draftSignature,
                payload: {
                    product_id: this.product,
                    from_warehouse_id: this.source,
                    to_warehouse_id: this.destination,
                    quantity: this.quantity,
                    priority: this.priority,
                    requested_arrival_at: arrival,
                    shipping_preference: this.shippingPreference,
                    reason: this.reason.trim() || null,
                    note: this.note.trim() || null,
                },
            };
            this.persistSubmission();
            return this.completeSubmission(this.pending, false);
        },

        async completeSubmission(operation, recovering) {
            const result = await this.sendIntent(this.endpoint, 'POST', operation.payload, operation.key);
            const draftChanged = this.draftSignature !== operation.draftSignature;
            if (result) {
                this.pending = null;
                this.submitted = true;
                if (draftChanged) this.recoveredIntentId = result.intent.id;
                this.persistSubmission();

                if (draftChanged) {
                    this.$nextTick(() => this.$refs.recoverySuccess?.focus());
                    return;
                }
                const destination = new URL(this.intentDetailUrl(result.intent.id), window.location.href);
                destination.searchParams.set('submitted', '1');
                window.location.replace(destination);
                return;
            }

            const validationRejected = this.submissionStatus === 422 && Object.keys(this.fieldErrors).length > 0;
            const rejected = validationRejected || this.conflict
                || (!recovering && [401, 403, 404, 419].includes(this.submissionStatus));
            if (rejected) {
                this.pending = null;
                this.persistSubmission();
                if (recovering && draftChanged && validationRejected) {
                    const [error] = Object.values(this.fieldErrors).flat();
                    this.fieldErrors = {};
                    this.submissionError = 'Az eredeti igény mentése nem történt meg. ' + error;
                }
                this.$nextTick(() => this.$refs.errorNotice?.focus());
                return;
            }

            this.persistSubmission();
            this.$nextTick(() => this.$refs.recoveryNotice?.focus());
        },
    }));
};
