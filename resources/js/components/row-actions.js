export default () => ({
    open: false,
    items() { return [...this.$refs.menu.querySelectorAll('[role="menuitem"]:not(:disabled)')]; },
    changed(event) {
        this.open = event.newState === 'open';
        if (this.open) {
            this.position();
            if (!this.$refs.menu.contains(document.activeElement)) this.items()[0]?.focus({ preventScroll: true });
        }
    },
    position() {
        if (!this.$refs.menu.matches(':popover-open')) return;
        const anchor = this.$refs.trigger.getBoundingClientRect();
        const menu = this.$refs.menu.getBoundingClientRect();
        const left = Math.max(8, Math.min(anchor.right - menu.width, window.innerWidth - menu.width - 8));
        const fitsBelow = anchor.bottom + 4 + menu.height <= window.innerHeight - 8;
        const top = fitsBelow ? anchor.bottom + 4 : Math.max(8, anchor.top - menu.height - 4);
        Object.assign(this.$refs.menu.style, { left: left + 'px', top: top + 'px' });
    },
    keyboardOpen(last = false) {
        if (!this.$refs.menu.matches(':popover-open')) this.$refs.trigger.click();
        const items = this.items();
        items[last ? items.length - 1 : 0]?.focus({ preventScroll: true });
    },
    navigate(event) {
        if (event.key === 'Tab') { this.$refs.menu.hidePopover(); return; }
        const items = this.items();
        const index = items.indexOf(document.activeElement);
        let next;
        if (event.key === 'ArrowDown') next = (index + 1) % items.length;
        else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = items.length - 1;
        else return;
        event.preventDefault();
        items[next]?.focus({ preventScroll: true });
    },
    leave(event) {
        if (!this.$refs.menu.contains(event.relatedTarget) && event.relatedTarget !== this.$refs.trigger) this.$refs.menu.hidePopover();
    },
});
