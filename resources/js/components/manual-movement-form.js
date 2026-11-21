import movementForm from './movement-form';
import transferQuantity, { positiveInteger } from './transfer-quantity';
import { operationKey } from './transfer-intent-submission';
import manualReceiptForm from './manual-receipt-form';

const maximum = 2147483647;
const keyOf = source => String(source.location_id ?? 'unassigned');

async function readJson(url, payload) {
    const options = { cache: 'no-store', headers: { Accept: 'application/json' } };
    if (payload) Object.assign(options, { method: 'POST', body: JSON.stringify(payload), headers: {
        ...options.headers, 'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    } });
    const response = await fetch(url, options);
    return { response, data: await response.json().catch(() => null) };
}

function errorText(response, data, fallback) {
    if (response.redirected || [401, 419].includes(response.status)) return 'A munkamenet lejárt. Jelentkezz be újra, majd próbáld meg ismét.';
    if (response.status === 403) return 'Ehhez a művelethez nincs jogosultságod.';
    if (response.status === 409) return 'A készlet vagy a mozgás adatai közben megváltoztak. Frissítsd az elérhetőséget és ellenőrizd újra.';
    if (response.status === 422 && data?.errors) return Object.values(data.errors).flat().join(' ');
    return fallback;
}

export default initial => {
    const base = movementForm(initial);
    const { init: initStock, loadStock: loadBalance, restored: restoreStock } = base;
    Object.defineProperties(base, Object.getOwnPropertyDescriptors(transferQuantity({ pieces: initial.quantity, boxFieldId: 'movement-quantity' })));
    Object.defineProperties(base, Object.getOwnPropertyDescriptors(manualReceiptForm(initial)));

    return Object.defineProperties(base, Object.getOwnPropertyDescriptors({
        sources: null, sourceLoading: false, sourceFailure: '', sourceRequest: 0,
        advice: null, adviceBasis: null, adviceLoading: false, adviceRequest: 0, adviceFailure: '',
        automatic: true, selected: [], pickerBinId: '',
        checkLoading: false, checkRequest: 0, issueError: '', review: null,
        savingIssue: false, attempt: null, pending: false, receiptUrl: '',
        issueOptions: null, issueOptionRequest: 0, issueOptionLoading: false, issueOptionFailure: '',
        issueOptionNotice: '', lastIssueChoice: 'product',

        init() {
            initStock.call(this);
            this.$watch('sourceScope', () => {
                this.pickerBinId = '';
                this.selected = [];
                this.automatic = true;
            });
            this.$watch('canLoadSources', () => this.refreshSources());
            this.$watch('type', () => {
                this.usePieces();
                this.clearIntentFeedback();
                this.issueOptionNotice = '';
                this.lastIssueChoice = 'product';
                this.refreshIssueOptions();
                this.loadStock();
            });
            this.$watch('requestBasis', () => this.clearAdvice());
            this.$watch('issueSignature', () => this.invalidateCheck());
            this.refreshIssueOptions();
        },

        restored(event) {
            restoreStock.call(this, event);
            if (!event.persisted || this.pending || this.receiptUrl) return;
            this.invalidateCheck();
            this.refreshIssueOptions();
            this.refreshSources();
        },

        submit(event) {
            event.preventDefault();
            if (this.type === 'in') this.reviewReceipt();
            else this.checkIssue();
        },

        get packaging() { return initial.packagings[this.product] ?? null; },
        get receiptSku() { return initial.productSkus[this.product] ?? ''; },
        get partnerName() { return this.partnerNames[this.partner] ?? ''; },
        get showIssueTotals() { return this.type === 'out'; },
        get shownBalance() { return this.type === 'out' ? this.sources : this.balance; },
        loadStock() {
            if (this.type !== 'out') return loadBalance.call(this);
        },
        productChanged(value) {
            if (this.product === value) return;
            this.usePieces();
            this.product = value;
            this.issueChoiceChanged('product', value);
        },
        warehouseChanged(value) {
            if (this.warehouse === value) return;
            this.warehouse = value;
            this.issueChoiceChanged('warehouse', value);
        },
        issueChoiceChanged(field, value) {
            this.issueOptionNotice = '';
            if (value) this.lastIssueChoice = field;
            this.refreshIssueOptions();
            this.loadStock();
        },
        get issueProductOptions() {
            return this.issueOptions?.warehouse === this.warehouse ? this.issueOptions.products : null;
        },
        get issueWarehouseOptions() {
            return this.issueOptions?.product === this.product ? this.issueOptions.warehouses : null;
        },
        get issueProductIds() { return this.type === 'out' ? this.issueProductOptions?.ids ?? [] : null; },
        get issueWarehouseIds() { return this.type === 'out' ? this.issueWarehouseOptions?.ids ?? [] : null; },
        get issueProductDetails() { return this.type === 'out' ? this.issueProductOptions?.details ?? {} : {}; },
        get issueWarehouseDetails() { return this.type === 'out' ? this.issueWarehouseOptions?.details ?? {} : {}; },
        get issuePickerEmptyText() {
            if (this.type !== 'out') return 'Nincs egyező találat.';
            if (this.issueOptionLoading) return 'Kiadási lehetőségek betöltése…';
            return this.issueOptionFailure || 'Nincs kiadható készlettel egyező találat.';
        },
        summarizeIssueChoices(rows) {
            return {
                ids: rows.map(row => row.id),
                details: Object.fromEntries(rows.filter(row => row.pickable_quantity != null).map(row => [
                    row.id, 'Kitárolható: ' + this.formatStock(row.pickable_quantity)
                    + ' · Közvetlenül: ' + this.formatStock(row.direct_issue_quantity),
                ])),
            };
        },
        async refreshIssueOptions() {
            const id = ++this.issueOptionRequest;
            this.issueOptionFailure = '';
            this.issueOptionLoading = this.type === 'out';
            if (this.type !== 'out') {
                this.issueOptions = null;
                return;
            }

            const { product, warehouse } = this;
            const url = new URL(initial.issueOptionsUrl);
            if (product) url.searchParams.set('product_id', product);
            if (warehouse) url.searchParams.set('warehouse_id', warehouse);
            const isCurrent = () => id === this.issueOptionRequest
                && this.type === 'out' && product === this.product && warehouse === this.warehouse;
            const failed = 'A kiadási lehetőségek nem tölthetők be. Próbáld újra.';
            try {
                const { response, data } = await readJson(url);
                if (!isCurrent()) return;
                if (!response.ok || !Array.isArray(data?.products) || !Array.isArray(data?.warehouses)) {
                    this.issueOptionFailure = errorText(response, data, failed);
                    return;
                }
                this.issueOptions = {
                    product, warehouse,
                    products: this.summarizeIssueChoices(data.products),
                    warehouses: this.summarizeIssueChoices(data.warehouses),
                };
                const invalidProduct = product && !this.issueProductIds.includes(product);
                const invalidWarehouse = warehouse && !this.issueWarehouseIds.includes(warehouse);
                if (invalidProduct && invalidWarehouse) {
                    this.clearIssueChoice(this.lastIssueChoice === 'warehouse' ? 'product' : 'warehouse');
                } else if (invalidProduct) this.clearIssueChoice('product');
                else if (invalidWarehouse) this.clearIssueChoice('warehouse');
            } catch {
                if (isCurrent()) this.issueOptionFailure = failed;
            } finally {
                if (id === this.issueOptionRequest) this.issueOptionLoading = false;
            }
        },
        clearIssueChoice(field) {
            const product = field === 'product';
            if (product) this.usePieces();
            this[field] = '';
            const message = product
                ? 'A korábbi termékválasztást töröltük, mert a termék jelenleg nem adható ki a választható raktárból.'
                : 'A korábbi raktárválasztást töröltük, mert a raktár jelenleg nem választható ehhez a kiadáshoz.';
            this.issueOptionNotice = [this.issueOptionNotice, message].filter(Boolean).join(' ');
            this.refreshIssueOptions();
        },
        get sourceScope() { return JSON.stringify([this.type, this.product, this.warehouse]); },
        get requestBasis() { return JSON.stringify([this.sourceScope, this.quantityValid, this.quantity, this.payload.source_allocations]); },
        get canLoadSources() {
            return this.type === 'out' && Boolean(this.product && this.warehouse)
                && this.issueProductIds.includes(this.product) && this.issueWarehouseIds.includes(this.warehouse);
        },
        get availableBins() {
            return (this.sources?.sources ?? []).filter(source => source.location_id !== null && source.active && source.available_quantity > 0);
        },
        get shelfChoices() {
            return this.availableBins.filter(source => !this.rows.some(row => row.key === keyOf(source)));
        },
        get manualChoices() {
            return this.shelfChoices.map(source => ({ value: keyOf(source), label: [source.code, source.position_label || source.name].filter(Boolean).join(' · ') }));
        },
        findSource(key) { return this.sources?.sources.find(source => keyOf(source) === key); },
        get loose() { return this.findSource('unassigned'); },
        sourceName(source) { return source?.code || source?.name || 'Tárhely nélküli készlet'; },
        get rows() {
            if (!this.automatic) return this.selected;
            if (this.quantityValid && this.loose?.available_quantity > 0) {
                return [{ key: 'unassigned', quantity: String(Math.min(this.quantity, this.loose.available_quantity)),
                    scope: this.sourceScope, source: this.loose }];
            }
            return [];
        },
        get selectedTotal() {
            return this.rows.reduce((sum, row) => sum + (positiveInteger(row.quantity, maximum) ? Number(row.quantity) : 0), 0);
        },
        get difference() { return this.quantityValid ? Number(this.quantity) - this.selectedTotal : null; },
        get selectionStatus() {
            if (this.difference === null) return 'Adj meg érvényes kiadási mennyiséget.';
            if (this.difference > 0) return 'Még ' + this.formatStock(this.difference) + ' kijelölendő.';
            if (this.difference < 0) return this.formatStock(-this.difference) + ' többlet van kijelölve.';
            if (this.rows.some(row => this.rowError(row))) return 'Ellenőrizd a megjelölt forrásokat.';
            return 'A forrásbontás teljes.';
        },
        get expectedStock() {
            return this.sources?.consistent && this.quantityValid && Number(this.quantity) <= this.sources.available_quantity
                ? this.sources.physical_quantity - Number(this.quantity) : null;
        },
        get sourceStatus() {
            if (!this.canLoadSources) return 'Válassz terméket és raktárt.';
            if (this.sourceLoading) return 'Forráshelyek betöltése…';
            if (this.sourceFailure) return this.sourceFailure;
            if (!this.sources) return '';
            if (!this.sources.consistent) return 'Eltérő készletadatok miatt a kiadás nem ellenőrizhető.';
            if (this.sources.physical_quantity === 0) return 'A termékből nincs készlet ebben a raktárban.';
            if (this.sources.available_quantity === 0) return 'Nincs kiadható készlet. Ellenőrizd a védett készletet és az inaktív rekeszeket.';
            if (this.quantityValid && Number(this.quantity) > this.sources.available_quantity) return 'A megadott mennyiség meghaladja a legutóbb lekért készletet.';
            if (this.quantityValid && !this.availableBins.length && this.loose?.available_quantity >= Number(this.quantity))
                return 'A kiadás tárhely nélküli készletből teljesíthető.';
            return '';
        },
        get reviewStatus() {
            if (this.checkLoading) return 'A szerver ellenőrzi a kiadást…';
            if (this.pending) return 'A mentés állapota még nem ismert.';
            if (this.receiptUrl) return 'A kiadás rögzítve.';
            if (!this.canLoadSources) return 'Az ellenőrzéshez termék és raktár szükséges.';
            if (this.sourceLoading) return 'Az elérhetőség ellenőrzése folyamatban van.';
            if (!this.sources?.consistent) return 'Frissítsd a készletelérhetőséget az ellenőrzéshez.';
            if (this.quantityValid && this.sources.available_quantity < Number(this.quantity)) {
                return 'Jelenleg legfeljebb ' + this.formatStock(this.sources.available_quantity) + ' tárolható ki.';
            }
            return this.canCheck ? 'A forrásbontás teljes. Indítsd az ellenőrzést.' : this.selectionStatus;
        },
        rowError(row) {
            if (row.scope !== this.sourceScope) return 'A forrás az előző termékhez vagy raktárhoz tartozik.';
            if (!positiveInteger(row.quantity, maximum)) return 'Pozitív egész darabszámot adj meg.';
            if (!this.sources) return '';
            const source = this.findSource(row.key);
            if (!source?.available_quantity) return 'Ez a forrás jelenleg nem használható.';
            if (Number(row.quantity) > source.available_quantity) return 'Ebből a forrásból legfeljebb ' + this.formatStock(source.available_quantity) + ' adható ki.';
            return '';
        },
        get canCheck() {
            return this.canLoadSources && this.quantityValid && !this.sourceLoading && this.sources?.consistent
                && Number(this.quantity) <= this.sources.available_quantity && this.difference === 0 && this.rows.length > 0
                && this.rows.every(row => !this.rowError(row)) && !this.checkLoading && !this.savingIssue && !this.pending && !this.receiptUrl;
        },
        get canRecommend() {
            return this.canLoadSources && this.quantityValid && !this.sourceLoading && this.sources?.consistent
                && this.availableBins.length > 0 && this.difference > 0 && this.rows.every(row => !this.rowError(row));
        },
        get payload() {
            return { type: 'out', product_id: this.product, warehouse_id: this.warehouse, quantity: Number(this.quantity),
                partner_id: this.partner || null, reference: this.reference.trim() || null,
                source_allocations: this.rows.map(row => ({ source_type: row.source.source_type, location_id: row.source.location_id,
                    quantity: Number(row.quantity) })).sort((a, b) => (a.location_id ?? 0) - (b.location_id ?? 0)),
            };
        },
        get issueSignature() { return JSON.stringify(this.payload); },

        clearAdvice() {
            this.adviceRequest++;
            this.adviceLoading = false;
            this.advice = null;
            this.adviceBasis = null;
            this.adviceFailure = '';
        },
        invalidateCheck() {
            this.checkRequest++;
            this.checkLoading = false;
            if (!this.pending && !this.savingIssue) this.closeReview();
        },

        sourceUrl(quantity = null) {
            const url = new URL(initial.sourcesUrl);
            url.searchParams.set('product_id', this.product);
            url.searchParams.set('warehouse_id', this.warehouse);
            if (quantity !== null) {
                url.searchParams.set('quantity', quantity);
                url.searchParams.set('recommend_bins', '1');
                this.payload.source_allocations.forEach((row, index) => {
                    for (const [field, value] of Object.entries(row)) {
                        if (value !== null) url.searchParams.set(`source_allocations[${index}][${field}]`, value);
                    }
                });
            }
            return url;
        },
        async refreshSources() {
            const id = ++this.sourceRequest;
            const scope = this.sourceScope;
            this.clearAdvice();
            this.invalidateCheck();
            this.sources = null;
            this.sourceFailure = '';
            this.sourceLoading = this.canLoadSources;
            if (!this.canLoadSources) return;
            try {
                const { response, data } = await readJson(this.sourceUrl());
                if (id !== this.sourceRequest || scope !== this.sourceScope) return;
                if (response.ok && Array.isArray(data?.sources)) this.sources = data;
                else this.sourceFailure = errorText(response, data, 'A forráshelyek nem tölthetők be. Frissítsd az elérhetőséget.');
            } catch {
                if (id === this.sourceRequest && scope === this.sourceScope) this.sourceFailure = 'A forráshelyek nem tölthetők be. Frissítsd az elérhetőséget.';
            } finally {
                if (id === this.sourceRequest && scope === this.sourceScope) this.sourceLoading = false;
            }
        },
        async recommend() {
            if (!this.canRecommend || this.adviceLoading) return;
            const id = ++this.adviceRequest;
            const basis = this.requestBasis;
            this.adviceLoading = true;
            this.adviceFailure = '';
            try {
                const { response, data } = await readJson(this.sourceUrl(this.quantity));
                if (id !== this.adviceRequest || basis !== this.requestBasis) return;
                if (response.ok && Array.isArray(data?.sources)) {
                    this.invalidateCheck();
                    this.sources = data;
                    if (basis !== this.requestBasis) return;
                    const candidates = data.suggestion?.length ? data.suggestion : data.partial_suggestion ?? [];
                    this.advice = candidates.map(row => ({ ...row, source: this.findSource(keyOf(row)) })).filter(row => row.source);
                    this.adviceBasis = basis;
                } else this.adviceFailure = errorText(response, data, 'Az ajánlás nem tölthető be. Próbáld újra vagy válassz forrást kézzel.');
            } catch {
                if (id === this.adviceRequest && basis === this.requestBasis) this.adviceFailure = 'Az ajánlás nem tölthető be. Próbáld újra vagy válassz forrást kézzel.';
            } finally {
                if (id === this.adviceRequest) this.adviceLoading = false;
            }
        },
        get adviceQuantity() { return (this.advice ?? []).reduce((sum, row) => sum + row.quantity, 0); },
        applyAdvice() {
            if (this.adviceBasis !== this.requestBasis || !this.canRecommend || !this.adviceQuantity || this.adviceQuantity > this.difference) return;
            this.makeManual();
            for (const row of this.advice) {
                const key = keyOf(row);
                const current = this.selected.find(source => source.key === key);
                if (current) current.quantity = String(Number(current.quantity) + row.quantity);
                else this.selected.push({ key, source: row.source, scope: this.sourceScope, quantity: String(row.quantity) });
            }
            this.clearAdvice();
            this.$nextTick(() => this.$refs.sourceHeading.focus({ preventScroll: true }));
        },
        makeManual() {
            if (!this.automatic) return;
            this.selected = this.rows.map(row => ({ ...row }));
            this.automatic = false;
        },
        addSource(key) {
            const source = this.findSource(key);
            if (!source?.available_quantity || this.rows.some(row => row.scope === this.sourceScope && row.key === key)) return;
            this.makeManual();
            const fill = Math.min(Math.max(0, this.difference ?? 0), source.available_quantity);
            this.selected.push({ key, source, scope: this.sourceScope, quantity: fill ? String(fill) : '' });
            this.pickerBinId = '';
            this.$nextTick(() => this.$refs.sourceTable.querySelector(`[data-source-key="${key}"]`)?.focus({ preventScroll: true }));
        },
        changeRow(index, value) { this.makeManual(); this.selected[index].quantity = value; },
        removeRow(index) {
            this.makeManual();
            this.selected.splice(index, 1);
            this.$nextTick(() => {
                const buttons = this.$refs.sourceTable.querySelectorAll('[data-remove-source]');
                const next = buttons[Math.min(index, buttons.length - 1)] ?? this.$refs.sourceHeading;
                next.focus({ preventScroll: true });
            });
        },

        closeReview() {
            if (!this.$refs.review?.open) return;
            this.$refs.review.close();
            if (!this.receiptUrl) this.$nextTick(() => {
                const trigger = this.pending ? this.$refs.pendingTrigger : this.$refs.checkTrigger;
                if (trigger?.getClientRects().length && !trigger.disabled) trigger.focus();
                else this.$refs.sourceHeading.focus();
            });
        },
        showReview() {
            if (!this.$refs.review.open) this.$refs.review.showModal();
            this.$nextTick(() => this.$refs.reviewTitle.focus());
        },
        async checkIssue() {
            if (!this.canCheck || !this.$refs.form.reportValidity()) return;
            const id = ++this.checkRequest;
            const signature = this.issueSignature;
            const payload = this.payload;
            this.checkLoading = true;
            this.issueError = '';
            try {
                const { response, data } = await readJson(initial.validateUrl, payload);
                if (id !== this.checkRequest || signature !== this.issueSignature) return;
                if (response.ok && data?.valid === true) {
                    const label = name => this.$refs.form.querySelector('#movement-' + name).value;
                    this.review = { signature, payload, rows: this.rows.map(row => ({ key: row.key, name: this.sourceName(row.source), quantity: row.quantity })),
                        product: label('product'), warehouse: label('warehouse'), partner: payload.partner_id ? label('partner') : '' };
                    this.showReview();
                } else this.issueError = errorText(response, data, 'A kiadás ellenőrzése sikertelen. Próbáld újra.');
            } catch {
                if (id === this.checkRequest && signature === this.issueSignature) this.issueError = 'A kapcsolat megszakadt. Ellenőrizd újra a kiadást.';
            } finally {
                if (id === this.checkRequest) this.checkLoading = false;
            }
        },
        async confirmIssue() {
            if (this.savingIssue || this.receiptUrl || !this.review || (!this.pending && this.review.signature !== this.issueSignature)) return;
            const { payload, signature } = this.review;
            if (this.attempt?.signature !== signature) this.attempt = { signature, key: this.attempt ? operationKey() : initial.requestKey };
            this.savingIssue = true;
            this.issueError = '';
            try {
                const { response, data } = await readJson(this.$refs.form.action, { ...payload, request_key: this.attempt.key });
                if (response.ok && Number.isInteger(data?.id)) {
                    this.pending = false;
                    this.receiptUrl = initial.detailUrl.replace('__movement__', String(data.id));
                    this.closeReview();
                    window.location.assign(this.receiptUrl);
                    return;
                }
                if (response.redirected || response.status >= 500 || response.ok) this.pending = true;
                else if (![401, 419].includes(response.status)) this.pending = false;
                if (response.status === 422 && data?.errors?.request_key) this.attempt = { signature: null };
                this.issueError = errorText(response, data, 'A mentés eredménye nem ellenőrizhető. Próbáld újra ugyanazt a kiadást.');
            } catch {
                this.pending = true;
                this.issueError = 'A kapcsolat megszakadt. Próbáld újra ugyanazt a kiadást.';
            } finally {
                this.savingIssue = false;
                if (!this.pending && !this.receiptUrl) {
                    this.closeReview();
                    this.review = null;
                }
            }
        },
    }));
};
