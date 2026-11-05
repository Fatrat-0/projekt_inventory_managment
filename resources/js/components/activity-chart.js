export default (days) => ({
    days,
    hoveredIndex: null,
    focusedIndex: null,
    selectedIndex: 0,
    dismissed: false,
    left: 0,
    top: 0,
    formatter: new Intl.NumberFormat('hu-HU'),
    get activeIndex() { return this.dismissed ? null : this.hoveredIndex ?? this.focusedIndex; },
    get activeDay() { return this.activeIndex === null ? null : this.days[this.activeIndex]; },
    init() { this.$watch('activeIndex', () => this.$nextTick(() => this.position())); },
    hover(index, event) {
        if (event.pointerType === 'touch') return;
        this.hoveredIndex = index;
        this.dismissed = false;
    },
    focus(index) {
        this.focusedIndex = index;
        this.selectedIndex = index;
        this.hoveredIndex = null;
        this.dismissed = false;
    },
    focusDay(index) {
        const links = this.$refs.targets.querySelectorAll('a');
        links[Math.max(0, Math.min(index, links.length - 1))]?.focus();
    },
    format(value) { return this.formatter.format(value ?? 0); },
    position() {
        if (this.activeIndex === null) return;
        const plot = this.$root.getBoundingClientRect();
        if (plot.bottom <= 0 || plot.top >= window.innerHeight) {
            this.dismissed = true;
            return;
        }
        const tooltip = this.$refs.tooltip.getBoundingClientRect();
        const center = (this.activeIndex + 0.5) / this.days.length * plot.width;
        this.left = Math.max(0, Math.min(center - tooltip.width / 2, plot.width - tooltip.width));
        const preferredTop = plot.top >= tooltip.height + 8 ? -tooltip.height : plot.height;
        this.top = Math.max(8 - plot.top, Math.min(preferredTop, window.innerHeight - tooltip.height - 8 - plot.top));
    },
});
