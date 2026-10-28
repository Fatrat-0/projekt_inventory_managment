export default (initial) => ({
    stops: [], origin: initial.origin, warehouses: initial.warehouses, counter: 0, submitting: false,
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
    addItem(stop) { if (stop.items.length < 50) stop.items.push({ key: ++this.counter, product_id: '', quantity: 1 }); },
    move(index, direction) {
        const next = index + direction;
        if (next < 0 || next >= this.stops.length) return;
        const stop = this.stops.splice(index, 1)[0];
        this.stops.splice(next, 0, stop);
    },
    setOrigin(value) {
        this.origin = String(value);
        for (const stop of this.stops) if (String(stop.warehouse_id) === this.origin) stop.warehouse_id = '';
    },
    warehouseName(id) { return this.warehouses.find(w => String(w.value) === String(id))?.label ?? 'Válassz célraktárt'; },
    get loadingOrder() { return this.stops.map((stop, index) => ({ ...stop, deliveryPosition: index + 1 })).reverse(); },
});
