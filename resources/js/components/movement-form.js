export default (initial) => ({
    ...initial, balance: null, stock: null, loading: false, failed: false, submitting: false, requestId: 0,
    init() { this.loadStock(); },
    get stockText() {
        if (!this.product || !this.warehouse) return 'Válassz terméket és raktárt a készlet megtekintéséhez.';
        if (this.loading) return 'Készlet lekérése…';
        if (this.failed) return 'A készlet nem tölthető be. Próbáld újra a frissítést.';
        return '';
    },
    get shownBalance() { return this.balance; },
    get stockLines() {
        const data = this.shownBalance;
        if (!data) return [];
        return [
            ['Fizikai készlet', data.physical_quantity],
            ['Közvetlenül kiadható', data.direct_issue_quantity],
            ...(this.showIssueTotals || data.pickable_quantity !== data.direct_issue_quantity ? [['Kitárolható összesen', data.pickable_quantity]] : []),
            ...[
                ['Betárolásra vár', data.pending_putaway_quantity],
                ['Foglalt', data.source_reserved_quantity],
                ['Összekészítve', data.staged_quantity],
                ['Elkülönítve', data.quarantined_quantity],
                ['Inaktív rekeszben', data.inactive_bin_quantity],
                ['Rekeszhez rendelve', data.assigned_quantity],
            ].filter(([, quantity]) => quantity > 0),
        ];
    },
    formatStock(quantity) {
        return quantity === null ? 'Nem számítható' : new Intl.NumberFormat('hu-HU').format(quantity) + ' db';
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
        this.balance = null; this.stock = null; this.failed = false; this.loading = false;
        if (!this.product || !this.warehouse) return;
        this.loading = true;
        try {
            const url = new URL(this.endpoint);
            url.searchParams.set('product_id', this.product);
            url.searchParams.set('warehouse_id', this.warehouse);
            url.searchParams.set('details', '1');
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Stock request failed');
            const data = await response.json();
            if (!Number.isInteger(data.physical_quantity) || typeof data.consistent !== 'boolean') throw new Error('Invalid stock response');
            if (id === this.requestId) {
                this.balance = data;
                this.stock = data.direct_issue_quantity;
            }
        } catch {
            if (id === this.requestId) this.failed = true;
        } finally {
            if (id === this.requestId) this.loading = false;
        }
    },
});
