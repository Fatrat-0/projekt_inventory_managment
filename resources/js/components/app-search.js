export default initial => ({
    query: '', results: initial.results, moreUrl: null, open: false, loading: false, error: '', active: -1,
    controller: null, requestId: 0, timer: null, previousFocus: null, previousOverflow: null,
    show(event) {
        if (event?.isComposing || document.querySelector('dialog[open]')) return;
        window.dispatchEvent(new Event('app-search-opening'));
        this.previousFocus = document.activeElement;
        this.previousOverflow = document.body.style.overflow;
        this.$refs.dialog.showModal();
        this.open = true;
        document.body.style.overflow = 'hidden';
        if (this.query.trim()) { this.results = []; this.search(); }
        else { this.results = initial.results; this.moreUrl = null; this.active = -1; this.error = ''; }
        this.$nextTick(() => this.$refs.input.focus({ preventScroll: true }));
    },
    shortcut(event) {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
            if (this.$refs.dialog.open) { event.preventDefault(); this.$refs.input.focus({ preventScroll: true }); return; }
            if (event.isComposing || document.querySelector('dialog[open]')) return;
            event.preventDefault(); this.show(event);
        }
    },
    edit() {
        clearTimeout(this.timer); this.controller?.abort(); ++this.requestId;
        this.active = -1; this.error = ''; this.results = []; this.moreUrl = null;
        this.loading = true;
        this.timer = setTimeout(() => this.search(), 200);
    },
    async search() {
        clearTimeout(this.timer); this.controller?.abort();
        const controller = new AbortController(); this.controller = controller;
        const requestId = ++this.requestId;
        this.loading = true; this.error = ''; this.active = -1;
        const url = new URL(initial.endpoint); url.searchParams.set('q', this.query);
        try {
            const response = await fetch(url, { signal: controller.signal, cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Search failed');
            const data = await response.json();
            if (requestId !== this.requestId || !this.open) return;
            this.results = data.results; this.moreUrl = data.moreUrl;
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === this.requestId) this.error = 'A keresés nem sikerült. Próbáld újra, vagy nyisd meg a teljes keresőt.';
        } finally { if (requestId === this.requestId) this.loading = false; }
    },
    move(direction) {
        if (!this.results.length) return;
        this.active = this.active < 0 ? (direction > 0 ? 0 : this.results.length - 1) : (this.active + direction + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.results.querySelectorAll('[role="option"]')[this.active]?.scrollIntoView({ block: 'nearest' }));
    },
    enter(event) {
        if (event.isComposing || event.keyCode === 229) return;
        if (this.active >= 0 && this.results[this.active]) { event.preventDefault(); window.location.assign(this.results[this.active].url); }
    },
    close() { if (this.$refs.dialog.open) this.$refs.dialog.close(); this.closed(); },
    closed() {
        if (this.$refs.dialog.open) return;
        const restore = this.open; this.open = false;
        clearTimeout(this.timer); this.controller?.abort(); ++this.requestId; this.loading = false;
        if (this.previousOverflow !== null) { document.body.style.overflow = this.previousOverflow; this.previousOverflow = null; }
        if (restore && this.previousFocus?.isConnected) this.previousFocus.focus({ preventScroll: true });
    },
    backdrop(event) {
        if (event.target !== this.$refs.dialog) return;
        const box = event.target.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) this.close();
    },
    get pageUrl() { const url = new URL(initial.endpoint); if (this.query.trim()) url.searchParams.set('q', this.query); return url.href; },
    destroy() { clearTimeout(this.timer); this.controller?.abort(); this.close(); },
});
