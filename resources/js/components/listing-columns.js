import rowActions from './row-actions';

// Keep native popover positioning and dismissal; checkboxes use ordinary Tab/Space navigation.
export default () => ({
    ...rowActions(),
    items() { return [...this.$refs.menu.querySelectorAll('input:not(:disabled), button:not(:disabled)')]; },
    refreshPosition() {
        this.$nextTick(() => {
            const table = this.$root.closest('[data-listing-table]');
            const scroller = table?.querySelector('[data-listing-scroll]') ?? table;
            if (scroller) {
                const region = scroller.getBoundingClientRect();
                const anchor = this.$refs.trigger.getBoundingClientRect();
                const left = Math.max(region.left, 0) + 4;
                const right = Math.min(region.right, window.innerWidth) - 4;
                if (anchor.right > right) scroller.scrollLeft += anchor.right - right;
                else if (anchor.left < left) scroller.scrollLeft += anchor.left - left;
            }
            this.position();
        });
    },
});
