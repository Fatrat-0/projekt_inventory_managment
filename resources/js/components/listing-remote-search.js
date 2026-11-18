export default initial => ({
    query: initial.query ?? '', items: [], open: false, active: -1,
    loading: false, error: '', more: false, controller: null, timer: null, requestId: 0,
    form: null, filterListener: null, submitListener: null, lastSignature: null, composing: false,
    init() {
        this.$nextTick(() => {
            this.form = this.$refs.input.form;
            this.filterListener = event => {
                if (event.target !== this.$refs.input && event.target.name) this.invalidate();
            };
            this.form.addEventListener('change', this.filterListener);
            this.submitListener = event => {
                if (this.composing) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                } else this.close();
            };
            // Block an unfinished IME submission before the parent enables its loader.
            this.form.addEventListener('submit', this.submitListener, true);
        });
    },
    get matches() { return this.items; },
    get results() { return this.items; },
    snapshot() {
        const form = this.$refs.input.form;
        const url = new URL(initial.endpoint, location.href);
        for (const [key, value] of new FormData(form)) url.searchParams.set(key, value);
        url.searchParams.set('search', this.query.trim());
        url.searchParams.set('suggestions', '1');
        url.searchParams.delete('page');
        url.searchParams.delete('per_page');
        return url.href;
    },
    cancelPending() {
        clearTimeout(this.timer);
        this.controller?.abort();
        this.controller = null;
        ++this.requestId;
        this.loading = false;
    },
    invalidate() {
        this.cancelPending();
        this.items = []; this.more = false; this.error = ''; this.lastSignature = null;
        this.open = false; this.active = -1;
    },
    edit() {
        this.cancelPending();
        this.items = []; this.more = false; this.error = ''; this.active = -1;
        if (this.composing) { this.open = false; return; }
        this.open = this.query.trim() !== '';
        if (!this.open || this.$refs.input.matches(':invalid')) return;
        this.loading = true;
        this.timer = setTimeout(() => this.search(), 200);
    },
    async search() {
        const signature = this.snapshot();
        const requestId = ++this.requestId;
        const controller = new AbortController();
        this.controller = controller;
        try {
            const response = await fetch(signature, {
                signal: controller.signal, cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error('Suggestions failed');
            const data = await response.json();
            if (requestId !== this.requestId || signature !== this.snapshot()) return;
            if (!Array.isArray(data.items)) throw new Error('Invalid suggestions');
            this.items = data.items.filter(item => typeof item.value === 'string'
                && typeof item.name === 'string' && typeof item.detail === 'string').slice(0, 8);
            this.more = data.has_more === true;
            this.lastSignature = signature;
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === this.requestId && signature === this.snapshot()) {
                this.error = 'A javaslatok nem tölthetők be. A megadott szövegre továbbra is kereshetsz.';
            }
        } finally {
            if (requestId === this.requestId) this.loading = false;
        }
    },
    move(direction) {
        if (!this.open && this.lastSignature !== this.snapshot()) { this.edit(); return; }
        this.open = this.query.trim() !== '';
        if (!this.results.length) return;
        this.active = this.active < 0 ? (direction > 0 ? 0 : this.results.length - 1)
            : (this.active + direction + this.results.length) % this.results.length;
        const list = this.$refs.list;
        const active = this.active;
        this.$nextTick(() => list.querySelectorAll('[role="option"]')[active]?.scrollIntoView({ block: 'nearest' }));
    },
    enter(event) {
        if (this.composing || event.isComposing || event.keyCode === 229) return;
        if (this.open && this.active >= 0) {
            event.preventDefault();
            this.choose(this.results[this.active]);
        } else this.close();
    },
    choose(item) {
        if (!item || this.lastSignature !== this.snapshot()) { this.invalidate(); return; }
        const form = this.$refs.input.form;
        this.query = item.value;
        this.close();
        this.$nextTick(() => form.requestSubmit());
    },
    close() {
        this.cancelPending();
        this.open = false; this.active = -1;
    },
    destroy() {
        this.cancelPending();
        if (this.form && this.filterListener) this.form.removeEventListener('change', this.filterListener);
        if (this.form && this.submitListener) this.form.removeEventListener('submit', this.submitListener, true);
    },
});
