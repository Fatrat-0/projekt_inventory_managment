import transferQuantity from './transfer-quantity';
import intentSubmission from './transfer-intent-submission';

const budapestOffset = new Intl.DateTimeFormat('hu-HU', {
    timeZone: 'Europe/Budapest',
    timeZoneName: 'longOffset',
});

function arrivalWithOffset(value) {
    const local = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})$/.exec(value);
    if (!local) return null;

    const civilTime = Date.parse(local[1] + ':00Z');
    if (!Number.isFinite(civilTime) || new Date(civilTime).toISOString().slice(0, 16) !== local[1]) return null;

    const day = 24 * 60 * 60 * 1000;
    const offsetAt = milliseconds => budapestOffset.formatToParts(milliseconds)
        .find(part => part.type === 'timeZoneName').value.replace('GMT', '');
    // Try both offsets around clock changes. A local time can correspond to zero or two instants.
    const offsets = [...new Set([offsetAt(civilTime - day), offsetAt(civilTime + day)])];
    const matchingOffsets = offsets.filter(offset => {
        const instant = Date.parse(local[1] + ':00' + offset);
        return Number.isFinite(instant) && offsetAt(instant) === offset;
    });
    return matchingOffsets.length === 1 ? local[1] + ':00' + matchingOffsets[0] : null;
}

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
        reason: '',
        note: '',
        submitted: false,

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
            if (this.submitting || this.submitted || this.conflict) return;

            const errors = {};
            if (!this.product) errors.product_id = ['Válassz terméket.'];
            if (!this.source) errors.from_warehouse_id = ['Válassz forrásraktárt.'];
            if (!this.destination) errors.to_warehouse_id = ['Válassz célraktárt.'];
            else if (this.source && !this.destinationOptions.includes(this.destination)) {
                errors.to_warehouse_id = ['Válassz másik célraktárt. Az egyik raktárhoz igénylési jogosultság szükséges.'];
            }
            if (!this.quantityValid) errors.quantity = [this.quantityInputError || 'Add meg a kért darabszámot.'];

            const arrival = this.arrival ? arrivalWithOffset(this.arrival) : null;
            if (!this.$refs.arrival.validity.valid) {
                errors.requested_arrival_at = ['Adj meg érvényes dátumot és időpontot.'];
            } else if (this.arrival && !arrival) {
                errors.requested_arrival_at = ['Ez az időpont magyarországi idő szerint nem létezik vagy kétszer fordul elő az óraátállításkor. Válassz másik időpontot.'];
            }

            if (Object.keys(errors).length) {
                this.clearIntentFeedback();
                this.fieldErrors = errors;
                this.submissionError = 'Ellenőrizd a megjelölt adatokat.';
                this.$nextTick(() => this.$refs.errorNotice?.focus());
                return;
            }

            const result = await this.sendIntent(this.endpoint, 'POST', {
                product_id: this.product,
                from_warehouse_id: this.source,
                to_warehouse_id: this.destination,
                quantity: this.quantity,
                priority: this.priority,
                requested_arrival_at: arrival,
                shipping_preference: this.shippingPreference,
                reason: this.reason.trim() || null,
                note: this.note.trim() || null,
            });
            if (result) {
                this.submitted = true;
                const destination = new URL(this.detailUrl.replace('__intent__', String(result.intent.id)), window.location.href);
                destination.searchParams.set('submitted', '1');
                window.location.replace(destination);
            } else {
                this.$nextTick(() => this.$refs.errorNotice?.focus());
            }
        },
    }));
};
