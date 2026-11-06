import Sortable from 'sortablejs';

export default initial => ({
    order: [...initial.order],
    defaults: initial.defaults,
    submitting: false,
    dragging: false,
    readOnly: Boolean(initial.readOnly),
    ready: false,
    beforeDrag: [],
    dragCancelled: false,
    announcement: '',
    init() {
        this.$nextTick(() => this.setupSort());
        this.$watch('submitting', value => {
            if (this.$refs.rows) Sortable.get(this.$refs.rows)?.option('disabled', value || this.readOnly);
        });
    },
    setupSort() {
        this.readOnly = this.$refs.form.querySelector('fieldset').disabled;
        Sortable.create(this.$refs.rows, {
            handle: '[data-layout-handle]',
            draggable: '[data-layout-row]',
            dataIdAttr: 'data-layout-row',
            direction: 'vertical',
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150,
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 4,
            disabled: this.readOnly,
            filter: 'input, a, button',
            preventOnFilter: false,
            ghostClass: 'dashboard-sort-ghost',
            chosenClass: 'dashboard-sort-chosen',
            dragClass: 'dashboard-sort-drag',
            scrollSensitivity: 64,
            scrollSpeed: 10,
            onClone: event => {
                event.clone.setAttribute('x-ignore', '');
                event.clone.setAttribute('aria-hidden', 'true');
            },
            onChoose: () => {
                this.beforeDrag = [...this.order];
                this.dragCancelled = false;
                this.dragging = true;
            },
            onStart: () => {
                // The floating copy sits outside Alpine's scope and must not initialize its bindings.
                Sortable.ghost?.setAttribute('x-ignore', '');
                Sortable.ghost?.setAttribute('aria-hidden', 'true');
            },
            onUnchoose: () => {
                this.dragging = false;
                this.$nextTick(() => { if (!this.dragging) this.beforeDrag = []; });
            },
            onEnd: event => this.finishDrag(event),
        });
        this.ready = true;
    },
    finishDrag(event) {
        const order = Sortable.get(this.$refs.rows).toArray();
        const valid = order.length === this.order.length && new Set(order).size === this.order.length && order.every(id => this.order.includes(id));
        if (this.dragCancelled || ['pointercancel', 'touchcancel'].includes(event.originalEvent?.type) || !valid) {
            this.reorder([...this.beforeDrag]);
            this.announcement = 'Az átrendezés megszakítva. Az eredeti sorrend megmaradt.';
        } else {
            this.order = order;
            this.announce(event.item.dataset.layoutRow, order.indexOf(event.item.dataset.layoutRow));
        }
        this.dragging = false;
        this.dragCancelled = false;
        this.beforeDrag = [];
    },
    cancelDrag() {
        if (!this.dragging) return;
        this.dragCancelled = true;
        // End the fallback drag through its standard cancellation events, including touch browsers.
        for (const type of ['pointercancel', 'touchcancel', 'mouseup']) {
            if (!this.dragging) break;
            this.$refs.rows.dispatchEvent(new Event(type, { bubbles: true }));
        }
    },
    restorePage() {
        this.cancelDrag();
        this.submitting = false;
    },
    announce(id, index) {
        const label = this.$refs.rows.querySelector(`[data-layout-row="${id}"]`).dataset.label;
        this.announcement = `${label}: ${index + 1}. hely.`;
    },
    move(id, direction, button) {
        if (this.submitting || this.dragging || this.readOnly) return;
        const index = this.order.indexOf(id);
        const target = index + direction;
        if (index < 0 || target < 0 || target >= this.order.length) return;
        const order = [...this.order];
        [order[index], order[target]] = [order[target], order[index]];
        this.reorder(order);
        this.announce(id, target);
        this.$nextTick(() => {
            // A boundary button becomes disabled after moving; use its opposite direction then.
            const row = this.$refs.rows.querySelector(`[data-layout-row="${id}"]`);
            const focus = button.disabled ? row.querySelector('button:not(:disabled)') : button;
            focus?.focus({ preventScroll: true });
            row.scrollIntoView({ block: 'nearest' });
        });
    },
    reorder(order) {
        const rows = new Map(Array.from(this.$refs.rows.children, row => [row.dataset.layoutRow, row]));
        order.forEach(id => this.$refs.rows.append(rows.get(id)));
        this.order = order;
    },
    resetDefaults() {
        if (this.submitting || this.dragging || this.readOnly) return;
        this.reorder([...this.defaults.order]);
        this.$refs.form.querySelectorAll('input[name="visible[]"]').forEach(input => { input.checked = this.defaults.visible.includes(input.value); });
        this.announcement = 'Az alapértelmezett elrendezés visszaállítva. A Mentés gombbal alkalmazható.';
    },
    submit(event) {
        if (this.submitting || this.dragging || this.readOnly) event.preventDefault();
        else this.submitting = true;
    },
    destroy() {
        if (!this.$refs.rows) return;
        this.cancelDrag();
        Sortable.get(this.$refs.rows)?.destroy();
    },
});
