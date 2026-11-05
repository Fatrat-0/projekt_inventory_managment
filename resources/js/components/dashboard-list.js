export default () => ({
    maxHeight: null,
    observer: null,
    init() {
        this.$nextTick(() => {
            if (!this.$refs.scroller) return;
            this.observer = new ResizeObserver(() => this.measure());
            this.observer.observe(this.$refs.scroller);
            this.$refs.scroller.querySelectorAll('[data-dashboard-row]').forEach(row => this.observer.observe(row));
            this.measure();
        });
    },
    measure() {
        if (!this.$refs.more?.open) {
            this.maxHeight = null;
            if (this.$refs.scroller) this.$refs.scroller.scrollTop = 0;
            return;
        }
        // Variable row heights and responsive wrapping must not create an unbounded panel.
        const rows = [...this.$refs.scroller.querySelectorAll('[data-dashboard-row]')].slice(0, 10);
        const height = rows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);
        this.maxHeight = Math.ceil(Math.min(height, window.innerHeight * 0.7));
    },
    destroy() { this.observer?.disconnect(); },
});
