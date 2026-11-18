export default ({ counts, url }) => ({
    counts,
    request: null,

    destroy() {
        this.request?.abort();
    },

    async refresh() {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            if (this.request !== request) return;
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                this.counts = {};
                return;
            }
            if (!response.ok) return;

            const { counts } = await response.json();
            if (this.request === request && counts && typeof counts === 'object') {
                this.counts = counts;
            }
        } catch {
            // Keep the last confirmed counts when the connection is unavailable.
        } finally {
            if (this.request === request) this.request = null;
        }
    },
});
