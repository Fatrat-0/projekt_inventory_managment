export default (initial) => ({
    ...initial,
    value: String(initial.value ?? ''), query: '', open: false, active: -1, available: null,
    init() {
        const input = this.$el.querySelector('input[role="combobox"]');
        // Text inputs strip line breaks from their HTML default before Alpine starts.
        if (input && input.value !== input.defaultValue.replace(/[\r\n]/g, '')) {
            const replaced = this.value !== '';
            this.value = '';
            this.query = input.value;
            this.open = document.activeElement === input;
            this.$nextTick(() => {
                this.syncValidity();
                if (replaced) this.notify();
            });
            return;
        }
        const selected = this.items.find(item => String(item.value) === this.value);
        this.query = selected?.label ?? '';
        if (!selected) {
            if (this.value && initial.invalidValueLabel) this.query = initial.invalidValueLabel;
            this.value = '';
        }
        this.$nextTick(() => this.syncValidity());
    },
    normalize(text) {
        return text.toLocaleLowerCase('hu').normalize('NFD').replace(/\p{M}/gu, '');
    },
    get results() {
        const selected = this.items.find(item => String(item.value) === this.value);
        const words = this.normalize(selected?.label === this.query ? '' : this.query).trim().split(/\s+/).filter(Boolean);
        const matches = [];
        for (const item of this.items) {
            if (this.available && !this.available.has(String(item.value))) continue;
            const text = this.normalize(item.search ?? item.label);
            if (!initial.searchEvent && !words.every(word => text.includes(word))) continue;
            matches.push(item);
            if (matches.length === this.maxResults) break;
        }
        return matches;
    },
    syncValue(value) {
        value = String(value ?? '');
        if (this.value === value) return;
        const item = this.items.find(item => String(item.value) === value);
        this.value = item ? value : '';
        this.query = item?.label ?? '';
        this.open = false;
        this.active = -1;
        this.syncValidity();
    },
    setAvailable(values) {
        this.available = values === null ? null : new Set(values);
        this.active = -1;
    },
    syncValidity() {
        this.$refs.input?.setCustomValidity(initial.validateSelection !== false && !this.value && this.query ? 'Válassz egy elemet a találati listából.' : '');
    },
    notify() { this.$dispatch(this.eventName, { value: this.value }); },
    edit() {
        const hadSelection = this.value !== '';
        this.value = '';
        this.open = true;
        this.active = -1;
        this.syncValidity();
        if (hadSelection) this.notify();
        if (initial.searchEvent) this.$dispatch(initial.searchEvent, { query: this.query });
    },
    choose(item) {
        this.value = String(item.value);
        this.query = item.label;
        this.open = false;
        this.active = -1;
        this.syncValidity();
        this.notify();
        this.$refs.input.focus();
        // Focus should not reopen a selection that was just made.
        this.open = false;
    },
    clear() {
        this.value = ''; this.query = ''; this.active = -1;
        this.syncValidity(); this.notify(); this.$refs.input.focus();
        if (initial.searchEvent) this.$dispatch(initial.searchEvent, { query: '' });
        // Clearing a filter must leave the apply/save buttons reachable below it.
        this.open = false;
    },
    move(direction) {
        this.open = true;
        if (!this.results.length) { this.active = -1; return; }
        this.active = this.active < 0 ? (direction > 0 ? 0 : this.results.length - 1)
            : (this.active + direction + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.list?.children[this.active]?.scrollIntoView({ block: 'nearest' }));
    },
    enter(event) {
        if (event.isComposing || event.keyCode === 229) return;
        if (!this.open) return;
        if (!this.required && !this.query.trim() && this.active < 0) {
            this.open = false;
            return;
        }
        event.preventDefault();
        const item = this.results[this.active < 0 ? 0 : this.active];
        if (item) this.choose(item);
    },
});
