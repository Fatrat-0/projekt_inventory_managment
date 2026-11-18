const number = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 3 });
const normalize = value => String(value ?? '').toLocaleLowerCase('hu').normalize('NFD').replace(/\p{M}/gu, '');

export default ({ urls, readJson }) => ({
    vehicles: [],
    vehicleSearch: '',
    vehicleSelection: null,
    vehicleLoadRequest: null,
    vehicleLoading: false,
    vehicleLoadError: '',

    get currentVehicle() { return this.cargo?.vehicle ?? null; },
    get vehicleResults() {
        const words = normalize(this.vehicleSearch).trim().split(/\s+/).filter(Boolean);
        return this.vehicles.filter(vehicle => {
            const text = normalize(`${vehicle.name} ${vehicle.registration_number ?? ''}`);
            return words.every(word => text.includes(word));
        });
    },
    get canSaveVehicleSelection() {
        return this.mayChange && !this.vehicleLoading && !this.vehicleLoadError
            && this.vehicleSelection !== (this.currentVehicle?.id ?? null)
            && (this.vehicleSelection === null || this.vehicles.some(vehicle => vehicle.id === this.vehicleSelection && vehicle.active));
    },

    vehiclePayloadLabel(grams) { return grams == null ? 'Nincs megadva' : this.formatMass(grams); },
    vehiclePalletLabel(slots) { return slots == null ? 'Nincs megadva' : `${number.format(slots)} hely`; },

    async loadVehicles() {
        this.vehicleLoadRequest?.abort();
        const controller = new AbortController();
        this.vehicleLoadRequest = controller;
        this.vehicleLoading = true;
        this.vehicleLoadError = '';
        try {
            const result = await readJson(urls.vehicles, controller.signal);
            if (this.vehicleLoadRequest === controller && !this.destroyed) this.vehicles = result.data.filter(vehicle => vehicle.active);
        } catch (error) {
            if (this.vehicleLoadRequest === controller && !this.destroyed) this.vehicleLoadError = error.message;
        } finally {
            if (this.vehicleLoadRequest === controller) {
                this.vehicleLoadRequest = null;
                this.vehicleLoading = false;
            }
        }
    },

    destroyVehicles() { this.vehicleLoadRequest?.abort(); },

    async openVehiclePicker() {
        if (!this.mayChange) return;
        this.vehicleSearch = '';
        this.vehicleSelection = this.currentVehicle?.id ?? null;
        this.openDialog(this.$refs.vehiclePicker, this.$refs.vehiclePickerSearch);
        await this.loadVehicles();
    },
    closeVehiclePicker() { this.closeDialog(this.$refs.vehiclePicker); },
    async refreshVehicleStatus() {
        await this.refreshPlan();
        if (!this.accessLost) this.$nextTick(() => this.$refs.vehicleSection?.focus());
    },
    pickVehicle(vehicle) {
        if (this.mayChange && !this.vehicleLoading && vehicle?.active) this.vehicleSelection = vehicle.id;
    },
    clearVehicleSelection() {
        if (this.mayChange && !this.vehicleLoading) this.vehicleSelection = null;
    },
    async saveVehicleSelection() {
        if (!this.canSaveVehicleSelection) return;
        await this.saveCommand('selectVehicle', urls.vehicle, 'PATCH', { vehicle_id: this.vehicleSelection }, 'vehiclePicker');
    },
});
