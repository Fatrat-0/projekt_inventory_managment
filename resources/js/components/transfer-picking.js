const wholeAmount = /^[1-9]\d*$/;
const sourceKey = id => id === null ? 'unassigned' : String(id);

export default ({ pickingEndpoint, previousSources }) => ({
    pickingContext: '',
    sourceRows: [],
    sourceDrafts: Object.fromEntries(previousSources.map(row => [sourceKey(row.location_id), String(row.quantity)])),

    get automaticSource() {
        if (this.sourceRows.length !== 1 || this.sourceRows[0].location_id !== null) return false;
        return Object.keys(this.sourceDrafts).every(key => key === 'unassigned');
    },

    get stockText() {
        if (this.loading) return 'A forráskészlet lekérése…';
        if (this.failed) return 'A forráskészlet nem tölthető be.';
        if (this.stock === null) return '';
        const quarantined = this.quarantinedStock ? `Elkülönítve: ${this.quarantinedStock} db` : '';
        const reserved = this.reservedStock ? `Raktári forrásfoglalás: ${this.reservedStock} db` : '';
        const staged = this.stagedStock ? `Összekészítve: ${this.stagedStock} db` : '';
        if (!this.automaticSource) return [`${this.stock} db kiadható összesen`, reserved, staged, quarantined].filter(Boolean).join(' · ');
        const row = this.sourceRows[0];
        return ['Tárhely nélküli készlet', `${row.stored_quantity} db nyilvántartva`,
            reserved || `${row.reserved_quantity} db foglalt`, `${row.available_quantity} db kiadható`, staged, quarantined].filter(Boolean).join(' · ');
    },

    async loadStock() {
        const context = JSON.stringify([this.product, this.warehouse]);
        if (this.pickingContext && this.pickingContext !== context) this.sourceDrafts = {};
        this.pickingContext = context;
        const id = ++this.requestId;
        this.sourceRows = [];
        this.stock = null;
        this.quarantinedStock = 0;
        this.reservedStock = 0;
        this.stagedStock = 0;
        this.loading = Boolean(this.product && this.warehouse);
        this.failed = false;
        if (!this.loading) return;

        try {
            const url = new URL(pickingEndpoint);
            url.searchParams.set('product_id', this.product);
            url.searchParams.set('from_warehouse_id', this.warehouse);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Picking sources request failed');
            const data = await response.json();
            if (!Number.isInteger(data.available_quantity) || !Array.isArray(data.sources)) throw new Error('Invalid picking sources');
            if (id !== this.requestId) return;

            this.stock = data.available_quantity;
            this.quarantinedStock = data.quarantined_quantity;
            this.reservedStock = data.source_reserved_quantity ?? 0;
            this.stagedStock = data.staged_quantity ?? 0;
            this.sourceRows = data.sources.map(row => ({ ...row, key: sourceKey(row.location_id) }));
        } catch {
            if (id === this.requestId) this.failed = true;
        } finally {
            if (id === this.requestId) this.loading = false;
        }
    },

    sourceLabel(row) { return row.code ? `${row.code} · ${row.name}` : row.name; },

    sourceAmount(row) { return this.automaticSource ? this.quantity : this.sourceDrafts[row.key] ?? ''; },

    editSource(row, value) { this.sourceDrafts[row.key] = value; },

    sourceInputError(row) {
        const value = this.sourceAmount(row);
        if (value === '' || value === '0') return '';
        if (!wholeAmount.test(value)) return 'Egész darabszámot adj meg.';
        return Number(value) > row.available_quantity ? `Legfeljebb ${row.available_quantity} db adható ki innen.` : '';
    },

    get sourceAllocations() {
        return this.sourceRows.flatMap(row => {
            const amount = this.sourceAmount(row);
            if (!wholeAmount.test(amount) || this.sourceInputError(row)) return [];
            return [{ location_id: row.location_id, quantity: Number(amount) }];
        });
    },

    get sourceTotal() { return this.sourceAllocations.reduce((total, row) => total + row.quantity, 0); },

    get sourceIssue() {
        if (this.loading) return 'Várd meg a forráskészlet betöltését.';
        if (this.failed) return 'Frissítsd a forráskészletet.';
        if (this.sourceRows.some(row => this.sourceInputError(row))) return 'Javítsd a forráshelyek mennyiségeit.';
        const visible = new Set(this.sourceRows.map(row => row.key));
        if (Object.entries(this.sourceDrafts).some(([key, value]) => value !== '' && value !== '0' && !visible.has(key))) {
            return 'Egy kiválasztott forráshely már nem elérhető. Frissítsd az elosztást.';
        }
        return this.sourceTotal === Number(this.quantity) ? '' : 'Oszd el a teljes mennyiséget a forráshelyek között.';
    },

    get sourceSummary() {
        if (!this.sourceTotal) return 'Még nincs megadva';
        const bins = this.sourceAllocations.filter(row => row.location_id !== null).length;
        const unassigned = this.sourceAllocations.some(row => row.location_id === null);
        const sources = [bins ? `${bins} rekesz` : '', unassigned ? 'tárhely nélkül' : ''].filter(Boolean);
        return `${sources.join(' + ')} · ${this.sourceTotal} db`;
    },

    applySourceSuggestion() {
        if (!this.quantityValid || this.quantityExceedsStock || this.loading || this.failed) return;
        let remaining = Number(this.quantity);
        const proposal = {};
        for (const row of this.sourceRows) {
            if (!remaining) break;
            const amount = Math.min(row.available_quantity, remaining);
            if (amount > 0) proposal[row.key] = String(amount);
            remaining -= amount;
        }
        this.sourceDrafts = proposal;
    },
});
