export default function listingSort(applied, formId) {
    return {
        primary: applied.sort_by,
        direction: applied.sort_dir,
        secondary: applied.then_by || '',
        secondaryDirection: applied.then_dir || 'asc',
        secondaryEnabled: Boolean(applied.then_by),
        isOpen: false,
        previousOverflow: null,
        resetDraft() {
            this.primary = applied.sort_by;
            this.direction = applied.sort_dir;
            this.secondary = applied.then_by || '';
            this.secondaryDirection = applied.then_dir || 'asc';
            this.secondaryEnabled = Boolean(applied.then_by);
        },
        restorePage(event) {
            if (!event.persisted) return;
            this.close();
            this.resetDraft();
        },
        openDialog() {
            const dialog = this.$refs.dialog;
            const primary = this.$refs.primary;
            if (dialog.open || document.querySelector('dialog[open]')) return;
            this.resetDraft();
            this.previousOverflow = document.body.style.overflow;
            dialog.showModal();
            this.isOpen = true;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => { if (dialog.open) primary.focus({ preventScroll: true }); });
        },
        primaryChanged() {
            if (this.primary === this.secondary) this.removeSecondary(false);
        },
        addSecondary() {
            const dialog = this.$refs.dialog;
            this.secondaryEnabled = true;
            this.$nextTick(() => {
                if (dialog.open) dialog.querySelector('[data-listing-sort-secondary]')?.focus({ preventScroll: true });
            });
        },
        removeSecondary(focus = true) {
            // Keep stable references before x-if removes the clicked button.
            const dialog = this.$refs.dialog;
            const trigger = this.$refs.addSecondary;
            this.secondary = '';
            this.secondaryDirection = 'asc';
            this.secondaryEnabled = false;
            if (focus) this.$nextTick(() => { if (dialog.open) trigger?.focus({ preventScroll: true }); });
        },
        close() {
            if (this.$refs.dialog?.open) this.$refs.dialog.close();
            this.closed();
        },
        closed() {
            // The native close event may arrive after a quick reopen.
            if (this.$refs.dialog?.open || !this.isOpen) return;
            const trigger = this.$refs.trigger;
            this.isOpen = false;
            this.resetDraft();
            if (this.previousOverflow !== null) {
                document.body.style.overflow = this.previousOverflow;
                this.previousOverflow = null;
            }
            if (trigger?.isConnected) trigger.focus({ preventScroll: true });
        },
        backdrop(event) {
            const dialog = this.$refs.dialog;
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) this.close();
        },
        trapFocus(event) {
            const dialog = this.$refs.dialog;
            const fields = [...dialog.querySelectorAll('button, select, [tabindex]:not([tabindex="-1"])')]
                .filter(field => !field.disabled && field.getClientRects().length);
            const first = fields[0];
            const last = fields.at(-1);
            if (!first) { event.preventDefault(); dialog.focus(); return; }
            if (event.shiftKey && (document.activeElement === first || !fields.includes(document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !fields.includes(document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        },
        apply() {
            const form = document.getElementById(formId);
            if (!form) return;
            const sort = { sort_by: this.primary, sort_dir: this.direction };
            if (this.secondaryEnabled && this.secondary && this.secondary !== this.primary) {
                sort.then_by = this.secondary;
                sort.then_dir = this.secondaryDirection;
            }
            this.close();
            // Keep this document's controls on its applied sort, including in BFCache.
            // Only the next GET should receive the draft.
            this.$nextTick(() => {
                const submitSort = ({ formData }) => {
                    for (const key of ['sort_by', 'sort_dir', 'then_by', 'then_dir']) {
                        if (sort[key]) formData.set(key, sort[key]);
                        else formData.delete(key);
                    }
                };
                form.addEventListener('formdata', submitSort);
                try {
                    form.requestSubmit();
                } finally {
                    form.removeEventListener('formdata', submitSort);
                }
            });
        },
        destroy() { this.close(); },
    };
}
