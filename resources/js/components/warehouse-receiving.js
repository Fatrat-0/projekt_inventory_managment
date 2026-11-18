import transferIntentSubmission from './transfer-intent-submission';

const number = new Intl.NumberFormat('hu-HU');
const date = new Intl.DateTimeFormat('hu-HU', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Budapest' });
const blankLine = () => ({ quantity: '', note: '' });

export default ({ snapshot, history, statuses, urls }) => ({
    ...transferIntentSubmission(result => result?.stop?.id === snapshot.stop.id && Boolean(result?.receipt?.id)),
    snapshot,
    history,
    statuses,
    draft: Object.fromEntries(snapshot.items.map(item => [item.id, blankLine()])),
    note: '',
    confirmed: false,
    success: null,
    needsRefresh: false,
    unconfirmed: false,
    refreshRequest: null,
    refreshError: '',
    historyRequest: null,
    historyError: '',
    accessError: '',
    destroyed: false,

    destroy() {
        this.destroyed = true;
        this.refreshRequest?.abort();
        this.historyRequest?.abort();
    },

    get draftLocked() {
        return this.submitting || this.unconfirmed || !!this.refreshRequest || !!this.accessError;
    },

    get hasDraft() {
        return !!this.note.trim() || this.snapshot.items.some(item => {
            const row = this.draft[item.id];
            return row.quantity !== '' || !!row.note.trim();
        });
    },

    get editableLines() {
        return this.snapshot.items.filter(item => item.remaining_quantity > 0);
    },

    get lineCounts() {
        const counts = { pending: 0, partial: 0, received: 0 };
        for (const item of this.snapshot.items) counts[this.lineStatus(item)]++;

        return counts;
    },

    lineStatus(item) {
        if (item.remaining_quantity === 0) return 'received';

        return item.received_quantity > 0 ? 'partial' : 'pending';
    },

    get lines() {
        return this.snapshot.items.flatMap(item => {
            const row = this.draft[item.id];
            const quantity = Number(row.quantity);
            if (quantity <= 0 || this.rowError(item)) return [];
            return [{ ...item, quantity, note: row.note.trim(), after: item.remaining_quantity - quantity }];
        });
    },

    get total() {
        return this.lines.reduce((sum, line) => sum + line.quantity, 0);
    },

    get remainder() {
        return this.snapshot.stop.remaining_quantity - this.total;
    },

    get canConfirm() {
        return this.snapshot.stop.can_receive && !this.draftLocked && !this.needsRefresh
            && this.lines.length > 0 && this.lines.length <= 1000
            && !this.snapshot.items.some(item => this.rowError(item));
    },

    quantity(value) {
        return number.format(value) + ' db';
    },

    formatDate(value) {
        return value ? date.format(new Date(value)) : '—';
    },

    putawayUrl(itemId) {
        return urls.putawayItem.replace('__ITEM__', encodeURIComponent(itemId));
    },

    putawayQueue(receiptId) {
        const url = new URL(urls.putaway, window.location.href);
        url.searchParams.set('receipt_id', receiptId);
        return url.toString();
    },

    itemFor(itemId) {
        return this.snapshot.items.find(item => item.id === itemId);
    },

    rowError(item) {
        const row = this.draft[item.id];
        const raw = row.quantity.trim();
        if (raw !== '' && !/^\d+$/.test(raw)) return 'Adj meg egész darabszámot.';
        if (Number(raw) > item.remaining_quantity) return 'Jelenleg legfeljebb ' + this.quantity(item.remaining_quantity) + ' vehető át.';
        if (row.note.trim() && !Number(raw)) return 'A megjegyzés mentéséhez adj meg átvett mennyiséget.';
        return '';
    },

    setFull(item) {
        if (this.draftLocked || !item.remaining_quantity) return;
        this.draft[item.id].quantity = String(item.remaining_quantity);
        this.edited();
    },

    setAll() {
        if (this.draftLocked) return;
        this.editableLines.forEach(item => this.setFull(item));
    },

    edited() {
        if (!this.needsRefresh) this.clearIntentFeedback();
    },

    openConfirmation() {
        if (!this.canConfirm) return;
        this.confirmed = false;
        this.$refs.confirmation.showModal();
    },

    closeConfirmation() {
        if (!this.submitting && !this.unconfirmed) this.$refs.confirmation.close();
    },

    async record() {
        if (!this.confirmed || this.submitting || (!this.unconfirmed && !this.canConfirm)) return;
        const payload = {
            version: this.snapshot.stop.version,
            confirmed: true,
            note: this.note.trim() || null,
            items: this.lines.map(line => ({ transit_item_id: line.id, quantity: line.quantity, note: line.note || null })),
        };
        const result = await this.sendIntent(urls.store, 'POST', payload);
        if (this.destroyed) return;
        if (!result) {
            if ([401, 403, 404, 419].includes(this.submissionStatus)) {
                this.revokeAccess(this.submissionError);
                return;
            }
            this.unconfirmed = this.submissionStatus === null || this.submissionStatus < 400 || this.submissionStatus >= 500;
            if (this.conflict || this.submissionStatus === 422) {
                this.needsRefresh = true;
                this.$nextTick(() => this.$refs.refreshInDialog?.focus());
            }
            return;
        }

        this.unconfirmed = false;
        this.confirmed = false;
        this.needsRefresh = true;
        this.snapshot = {
            ...this.snapshot,
            shipment: { ...this.snapshot.shipment, ...result.shipment },
            stop: {
                ...this.snapshot.stop, ...result.stop,
                can_receive: this.snapshot.stop.can_receive && result.stop.receiving_status !== 'received',
            },
        };
        this.success = {
            quantity: result.items.reduce((sum, item) => sum + item.received_quantity, 0),
            next: result.items.length === 1 ? this.putawayUrl(result.items[0].id) : this.putawayQueue(result.receipt.id),
            remaining: result.stop.remaining_quantity,
        };
        this.draft = Object.fromEntries(this.snapshot.items.map(item => [item.id, blankLine()]));
        this.note = '';
        this.resetIntentSubmission();
        this.$refs.confirmation.close();
        window.dispatchEvent(new CustomEvent('warehouse-tasks:changed'));
        this.$nextTick(() => this.$refs.success?.focus());
        await this.refresh({ keepFocus: true });
    },

    revokeAccess(message) {
        this.accessError = message;
        if (this.$refs.confirmation?.open) this.$refs.confirmation.close();
        this.$nextTick(() => this.$refs.accessNotice?.focus());
    },

    async get(url, signal) {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal });
        const data = await response.json().catch(() => null);
        const status = response.redirected ? 401 : response.status;
        if ([401, 403, 404, 419].includes(status)) {
            const message = [401, 419].includes(status) ? 'A munkamenet lejárt. Jelentkezz be újra.' :
                (data?.message || 'Ez az átvételi feladat már nem érhető el.');
            throw Object.assign(new Error(message), { accessLost: true });
        }
        if (!response.ok || !data) throw new Error('Az átvételi adatok most nem tölthetők be. Próbáld meg újra.');
        return data;
    },

    async refresh({ keepFocus = false } = {}) {
        if (this.refreshRequest || this.submitting || this.unconfirmed || this.accessError) return;
        if (this.$refs.confirmation?.open) this.$refs.confirmation.close();

        const request = new AbortController();
        this.refreshRequest = request;
        this.needsRefresh = true;
        this.refreshError = '';
        try {
            const current = await this.get(urls.state, request.signal);
            if (this.destroyed || this.refreshRequest !== request) return;
            if (current.stop?.id !== this.snapshot.stop.id) throw new Error('A célmegálló adatai nem tölthetők be.');
            this.draft = Object.fromEntries(current.items.map(item => [item.id, this.draft[item.id] ?? blankLine()]));
            this.snapshot = current;
            this.needsRefresh = false;
            this.confirmed = false;
            this.resetIntentSubmission();
            if (!keepFocus) this.$nextTick(() => this.$refs.lineHeading?.focus());
            await this.loadHistory(1);
        } catch (error) {
            if (this.destroyed || this.refreshRequest !== request) return;
            if (error.accessLost) return this.revokeAccess(error.message);
            this.refreshError = error.message;
        } finally {
            if (this.refreshRequest === request) this.refreshRequest = null;
        }
    },

    async loadHistory(page) {
        this.historyRequest?.abort();
        const request = new AbortController();
        this.historyRequest = request;
        this.historyError = '';
        const url = new URL(urls.history, window.location.href);
        url.searchParams.set('page', page);
        url.searchParams.set('per_page', 20);

        try {
            const data = await this.get(url, request.signal);
            if (this.destroyed || this.historyRequest !== request) return;
            this.history = data;
        } catch (error) {
            if (this.destroyed || this.historyRequest !== request) return;
            if (error.accessLost) return this.revokeAccess(error.message);
            this.historyError = 'Az átvételi előzmények most nem tölthetők be.';
        } finally {
            if (this.historyRequest === request) this.historyRequest = null;
        }
    },
});

