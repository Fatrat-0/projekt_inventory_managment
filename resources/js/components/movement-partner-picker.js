import autocompletePicker from './autocomplete-picker';

export default (initial) => {
    const picker = autocompletePicker(initial);
    const initialize = picker.init;
    // Preserve the base component's reactive results getter.
    return Object.assign(picker, {
        allItems: initial.items,
        init() {
            this.items = this.allItems.filter(item => item.types.includes(this.type));
            initialize.call(this);
            this.$watch('type', () => {
                this.items = this.allItems.filter(item => item.types.includes(this.type));
                // A dual-role partner stays selected when the movement direction changes.
                if (this.value && !this.items.some(item => String(item.value) === this.value)) {
                    this.value = ''; this.query = ''; this.notify();
                }
                this.open = false; this.active = -1;
                this.syncValidity();
            });
        },
    });
};
