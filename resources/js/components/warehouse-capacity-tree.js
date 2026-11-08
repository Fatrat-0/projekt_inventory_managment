export default () => ({
    loaded: false,
    loading: false,
    failed: false,

    async load() {
        if (this.loaded || this.loading) return;
        this.loading = true;
        this.failed = false;
        try {
            const response = await fetch(this.$el.dataset.capacityUrl, { headers: { Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error();
            this.$refs.capacity.innerHTML = await response.text();
            this.loaded = true;
        } catch {
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },
});
