export default (initial) => ({
    ...initial, stock: null, physicalStock: null, quarantinedStock: 0, assignedStock: 0, reservedStock: 0, stagedStock: 0, loading: false, failed: false, submitting: false, requestId: 0,
    init() { this.loadStock(); },
    get stockText() {
        if (!this.product || !this.warehouse) return 'Válassz terméket és raktárt a készlet megtekintéséhez.';
        if (this.loading) return 'Készlet lekérése…';
        if (this.failed) return 'A készlet nem tölthető be. Próbáld újra a frissítést.';
        if (this.reservedStock > 0 || this.stagedStock > 0) {
            return [`Tárhely nélkül kiadható: ${this.stock} db`,
                this.reservedStock ? `Forrásfoglalás: ${this.reservedStock} db` : '',
                this.stagedStock ? `Összekészítve: ${this.stagedStock} db` : '',
                `Fizikai készlet: ${this.physicalStock} db`].filter(Boolean).join(' · ');
        }
        if (this.assignedStock > 0) {
            return `Tárhely nélkül kiadható: ${this.stock} db · Tárhelyen: ${this.assignedStock} db · Elkülönítve: ${this.quarantinedStock} db`;
        }
        return this.quarantinedStock > 0
            ? `Kiadható készlet: ${this.stock} db · Elkülönítve: ${this.quarantinedStock} db · Fizikai készlet: ${this.physicalStock} db`
            : `Jelenlegi készlet a raktárban: ${this.stock} db`;
    },
    submit(event) {
        if (this.submitting) { event.preventDefault(); return; }
        this.submitting = true;
    },
    restored(event) {
        if (!event.persisted) return;
        this.submitting = false;
        this.loadStock();
    },
    async loadStock() {
        const id = ++this.requestId;
        this.stock = null; this.physicalStock = null; this.quarantinedStock = 0; this.assignedStock = 0; this.reservedStock = 0; this.stagedStock = 0; this.failed = false; this.loading = false;
        if (!this.product || !this.warehouse) return;
        this.loading = true;
        try {
            const url = new URL(this.endpoint);
            url.searchParams.set('product_id', this.product);
            url.searchParams.set('warehouse_id', this.warehouse);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Stock request failed');
            const data = await response.json();
            if (!Number.isInteger(data.quantity) || data.direct_issue_quantity === null) throw new Error('Invalid stock response');
            if (id === this.requestId) {
                this.assignedStock = data.assigned_quantity ?? 0;
                this.stock = data.direct_issue_quantity ?? data.quantity - this.assignedStock;
                this.reservedStock = data.source_reserved_quantity ?? 0;
                this.stagedStock = data.staged_quantity ?? 0;
                this.physicalStock = data.physical_quantity ?? data.quantity;
                this.quarantinedStock = data.quarantined_quantity ?? 0;
            }
        } catch {
            if (id === this.requestId) this.failed = true;
        } finally {
            if (id === this.requestId) this.loading = false;
        }
    },
});
