export default (initial) => ({
    ...initial, stock: null, loading: false, failed: false, submitting: false, requestId: 0,
    init() { this.loadStock(); },
    get stockText() {
        if (!this.product || !this.warehouse) return 'Válassz terméket és raktárt a készlet megtekintéséhez.';
        if (this.loading) return 'Készlet lekérése…';
        if (this.failed) return 'A készlet nem tölthető be. Próbáld újra a frissítést.';
        return `Jelenlegi készlet a raktárban: ${this.stock} db`;
    },
    submit(event) {
        if (this.submitting) { event.preventDefault(); return; }
        this.submitting = true;
    },
    async loadStock() {
        const id = ++this.requestId;
        this.stock = null; this.failed = false; this.loading = false;
        if (!this.product || !this.warehouse) return;
        this.loading = true;
        try {
            const url = new URL(this.endpoint);
            url.searchParams.set('product_id', this.product);
            url.searchParams.set('warehouse_id', this.warehouse);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Stock request failed');
            const data = await response.json();
            if (!Number.isInteger(data.quantity)) throw new Error('Invalid stock response');
            if (id === this.requestId) this.stock = data.quantity;
        } catch {
            if (id === this.requestId) this.failed = true;
        } finally {
            if (id === this.requestId) this.loading = false;
        }
    },
});
