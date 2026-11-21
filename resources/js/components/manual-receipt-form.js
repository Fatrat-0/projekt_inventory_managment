import intentSubmission from './transfer-intent-submission';

export default initial => ({
    ...intentSubmission(result => Boolean(result?.movement?.id && result?.movement_url), { keyField: 'request_key', firstKey: initial.requestKey }),
    receiptReview: null,
    receiptResult: null,
    receiptPending: false,

    get receiptPayload() {
        return { type: 'in', product_id: this.product, warehouse_id: this.warehouse, quantity: Number(this.quantity),
            partner_id: this.partner || null, reference: this.reference.trim() || null, context: initial.context, confirmed: true };
    },
    get receiptSignature() { return JSON.stringify([this.type, this.receiptPayload]); },
    get receiptOverflow() { return this.receiptExpectedStock !== null && this.receiptExpectedStock > 2147483647; },
    get receiptPhysicalStock() { return this.balance?.physical_quantity ?? null; },
    get receiptExpectedStock() {
        return this.receiptPhysicalStock !== null && this.quantityValid ? this.receiptPhysicalStock + Number(this.quantity) : null;
    },
    get canReviewReceipt() {
        return this.type === 'in' && Boolean(this.product && this.warehouse) && this.quantityValid && !this.receiptOverflow
            && !this.receiptPending && !this.submitting && !this.receiptResult;
    },
    get receiptNextStep() {
        if (!this.product || !this.warehouse) return 'Válassz terméket és célraktárt.';
        if (!this.quantityValid) return 'Adj meg pozitív egész mennyiséget.';
        if (this.receiptOverflow) return 'A raktári készlet meghaladná a megengedett 2 147 483 647 darabot.';
        return '';
    },
    reviewReceipt() {
        if (!this.canReviewReceipt || !this.$refs.form.reportValidity()) return;
        this.clearIntentFeedback();
        this.receiptReview = { payload: this.receiptPayload, signature: this.receiptSignature, before: this.receiptPhysicalStock,
            after: this.receiptExpectedStock, product: this.productNames[this.product], sku: initial.productSkus[this.product],
            warehouse: this.warehouseNames[this.warehouse], partner: this.partnerName };
        this.$refs.receiptDialog.showModal();
        this.$nextTick(() => this.$refs.receiptTitle.focus({ preventScroll: true }));
    },
    closeReceiptReview() {
        if (this.submitting || !this.$refs.receiptDialog?.open) return;
        this.$refs.receiptDialog.close();
        if (!this.receiptResult) this.$nextTick(() => (this.receiptPending ? this.$refs.receiptRetry : this.$refs.receiptReviewButton)?.focus({ preventScroll: true }));
    },
    async refreshReceiptPartners() {
        try {
            const response = await fetch(window.location.href, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) return;
            const items = await response.json();
            this.partnerNames = Object.fromEntries(items.map(({ value, name }) => [value, name]));
            window.dispatchEvent(new CustomEvent('movement-partners-updated', { detail: items }));
        } catch {
            // The validation error and the cleared selection remain available.
        }
    },
    async saveReceipt() {
        if (!this.receiptReview || this.submitting || this.receiptResult
            || (!this.receiptPending && this.receiptReview.signature !== this.receiptSignature)) return;
        const result = await this.sendIntent(this.$refs.form.action, 'POST', this.receiptReview.payload);
        if (result) {
            this.receiptResult = result;
            this.receiptPending = false;
            this.closeReceiptReview();
            this.$nextTick(() => this.$refs.receiptSuccess?.focus());
            window.dispatchEvent(new CustomEvent('warehouse-tasks:changed'));
        } else {
            this.receiptPending = this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500
                || (this.receiptPending && [401, 419].includes(this.submissionStatus));
            if (this.receiptPending) return;
            if (this.fieldErrors.request_key) this.intentAttempt = { signature: null };
            const invalidPartner = Boolean(this.fieldErrors.partner_id);
            if (invalidPartner) this.partner = '';
            this.closeReceiptReview();
            if (invalidPartner) await this.refreshReceiptPartners();
        }
    },
});
