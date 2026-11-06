export default (initial) => ({
    chartType: initial.chartType,
    days: initial.days,
    loading: false,
    error: '',
    controller: null,
    requestId: 0,
    setChart(type) {
        if (!['bar', 'line'].includes(type)) return;
        this.chartType = type;
        this.syncUrl(new URL(window.location.href));
    },
    syncUrl(url) {
        url.searchParams.set('chart', this.chartType);
        url.searchParams.set('days', String(this.days));
        url.hash = '';
        window.history.replaceState(window.history.state, '', url);
        // Pager and refresh links must follow client-side selections as well.
        this.$root.querySelectorAll('a[href]').forEach(link => {
            if (link.getAttribute('href').startsWith('#')) return;
            const target = new URL(link.href, window.location.href);
            if (target.origin !== url.origin || (target.pathname !== url.pathname && !link.hasAttribute('data-dashboard-customize'))) return;
            target.searchParams.set('chart', this.chartType);
            target.searchParams.set('days', String(this.days));
            link.href = target.href;
        });
    },
    async updatePeriod(form) {
        this.controller?.abort();
        const controller = new AbortController();
        this.controller = controller;
        const requestId = ++this.requestId;
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        url.hash = '';
        this.loading = true;
        this.error = '';
        try {
            const response = await fetch(url, { signal: controller.signal, cache: 'no-store', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error('Dashboard request failed');
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = document.querySelector('[data-activity-body]');
            if (!next) throw new Error('Dashboard activity missing');
            if (requestId !== this.requestId) return;
            const current = this.$root.querySelector('[data-activity-body]');
            const detailsOpen = current.querySelector('[data-daily-details]').open;
            next.querySelector('[data-daily-details]').open = detailsOpen;
            current.replaceWith(next);
            this.days = Number(url.searchParams.get('days'));
            this.syncUrl(url);
        } catch (error) {
            if (error.name === 'AbortError' || requestId !== this.requestId) return;
            form.querySelector('[name="days"]').value = String(this.days);
            this.error = 'A diagram nem frissült. Próbáld újra, vagy használd az oldal tetején a Frissítés lehetőséget.';
        } finally {
            if (requestId === this.requestId) this.loading = false;
        }
    },
    destroy() { this.controller?.abort(); },
});
