export default (initial) => ({
    stops: [], origin: initial.origin, warehouses: initial.warehouses, storageLocations: initial.storageLocations, counter: 0, submitting: false,
    init() {
        this.stops = initial.stops.map(stop => ({ ...stop, key: ++this.counter,
            items: stop.items.map(item => ({ ...item, key: ++this.counter })) }));
    },
    addStop() {
        if (this.canAddStop) this.stops.push({ key: ++this.counter, warehouse_id: '', items: [{ key: ++this.counter, product_id: '', quantity: 1 }] });
    },
    get canAddStop() {
        const destinations = this.warehouses.filter(warehouse => String(warehouse.value) !== String(this.origin)).length;
        return this.stops.length < Math.min(20, destinations);
    },
    destinationUnavailable(value, stop) {
        const id = String(value);
        return id === String(this.origin) || this.stops.some(other => other.key !== stop.key && String(other.warehouse_id) === id);
    },
    destinationChanged(stop) {
        for (const item of stop.items) {
            item.to_location_id = '';
            item.pallet_slots = '';
        }
    },
    addItem(stop) { if (stop.items.length < 50) stop.items.push({ key: ++this.counter, product_id: '', quantity: 1 }); },
    move(index, direction) {
        const next = index + direction;
        if (next < 0 || next >= this.stops.length) return;
        const stop = this.stops.splice(index, 1)[0];
        this.stops.splice(next, 0, stop);
    },
    setOrigin(value) {
        this.origin = String(value);
        for (const stop of this.stops) {
            if (String(stop.warehouse_id) !== this.origin) continue;
            stop.warehouse_id = '';
            this.destinationChanged(stop);
        }
    },
    serializeStops(formData) {
        const stops = this.stops.map((stop, stopIndex) => {
            const prefix = `stops[${stopIndex}]`;
            return {
                warehouse_id: formData.get(`${prefix}[warehouse_id]`),
                items: stop.items.map((item, itemIndex) => {
                    const itemPrefix = `${prefix}[items][${itemIndex}]`;
                    const fields = ['product_id', 'quantity', 'to_location_id', 'cargo_weight_kg', 'pallet_slots'];
                    return Object.fromEntries(fields.map(field => [field, formData.get(`${itemPrefix}[${field}]`)]));
                }),
            };
        });
        for (const name of [...formData.keys()]) if (name.startsWith('stops[')) formData.delete(name);
        formData.set('stops_json', JSON.stringify(stops));
    },
    warehouseName(id) { return this.warehouses.find(w => String(w.value) === String(id))?.label ?? 'Válassz célraktárt'; },
    get loadingOrder() { return this.stops.map((stop, index) => ({ ...stop, deliveryPosition: index + 1 })).reverse(); },
});
