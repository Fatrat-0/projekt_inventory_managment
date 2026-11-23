import intentSubmission from './transfer-intent-submission';
import { planningFieldError, planningInstant } from './planning-time';

const number = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });

export default ({ endpoint, detailUrl, vehiclesUrl, vehicles }) => ({
    ...intentSubmission(result => Boolean(result?.shipment?.id)),
    reference: '',
    origin: '',
    departure: '',
    vehicles,
    vehicleId: '',
    vehicleLoading: false,
    vehicleRefreshFailed: false,
    vehicleNeedsSelection: false,
    note: '',
    submitted: false,
    pending: null,

    get selectedVehicle() { return this.vehicles.find(vehicle => String(vehicle.id) === this.vehicleId); },
    get vehicleItems() {
        return this.vehicles.map(vehicle => ({
            value: String(vehicle.id),
            label: vehicle.registration_number ? vehicle.name + ' · ' + vehicle.registration_number : vehicle.name,
        }));
    },
    get vehicleDetails() {
        return Object.fromEntries(this.vehicles.map(vehicle => [
            vehicle.id,
            'Teherbírás: ' + (vehicle.payload_capacity_grams == null ? 'nincs megadva' : number.format(vehicle.payload_capacity_grams / 1000) + ' kg')
                + ' · Raklaphely: ' + (vehicle.pallet_slots_capacity == null ? 'nincs megadva' : number.format(vehicle.pallet_slots_capacity)),
        ]));
    },
    vehicleSelected(id) {
        this.vehicleId = id;
        if (this.vehicleLoading) return;
        this.vehicleNeedsSelection = false;
        delete this.fieldErrors.vehicle_id;
    },
    skipVehicle() {
        this.$refs.vehiclePicker.querySelector('[data-autocomplete-clear]').click();
    },
    async refreshVehicles() {
        if (this.vehicleLoading) return;
        this.vehicleLoading = true;
        this.vehicleRefreshFailed = false;
        this.vehicleNeedsSelection = true;
        this.vehicleId = '';
        try {
            const response = await fetch(vehiclesUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok || response.redirected) throw new Error();
            this.vehicles = (await response.json()).data.filter(vehicle => vehicle.active);
        } catch {
            this.vehicles = [];
            this.vehicleRefreshFailed = true;
        } finally {
            this.vehicleLoading = false;
        }
    },

    async save() {
        if (this.submitting || this.submitted || this.vehicleLoading) return;
        this.clearIntentFeedback();
        const data = this.pending ?? {
            reference: this.reference.trim(), origin_warehouse_id: Number(this.origin),
            planned_departure_at: planningInstant(this.departure), note: this.note.trim() || null,
            vehicle_id: this.selectedVehicle?.id ?? null, vehicle_version: this.selectedVehicle?.version ?? null,
        };
        if (!data.reference) this.fieldErrors.reference = ['Add meg a szállítmányhivatkozást.'];
        if (!data.origin_warehouse_id) this.fieldErrors.origin_warehouse_id = ['Válassz induló raktárat.'];
        const picker = this.$refs.vehiclePicker.querySelector('[role="combobox"]');
        if (!this.pending && (this.vehicleNeedsSelection || picker.validity.customError)) {
            this.fieldErrors.vehicle_id = ['Válassz járművet a listából, vagy ürítsd ki a mezőt a jármű nélküli tervezethez.'];
        }
        if (!this.pending) {
            const departureError = planningFieldError(this.$refs.departureInput, this.departure, data.planned_departure_at);
            if (departureError) this.fieldErrors.planned_departure_at = [departureError];
        }
        if (Object.keys(this.fieldErrors).length) {
            this.submissionError = 'Ellenőrizd a megjelölt adatokat.';
            this.$nextTick(() => this.$refs.feedback.focus());
            return;
        }

        const result = await this.sendIntent(endpoint, 'POST', data);
        if (result) {
            this.submitted = true;
            window.location.assign(detailUrl.replace('__shipment__', encodeURIComponent(result.shipment.id)));
            return;
        }
        this.pending = this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500 ? data : null;
        if (this.conflict) this.intentAttempt = null;
        if (this.fieldErrors.vehicle_id) await this.refreshVehicles();
        this.$nextTick(() => this.$refs.feedback.focus());
    },
});
