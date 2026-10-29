export default (initial) => ({
    ...initial,
    value: String(initial.value ?? ''), query: '', open: false, active: -1,
    init() {
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
        return this.items.filter(item => words.every(word => this.normalize(item.label).includes(word)));
    },
    syncValidity() {
        this.$refs.input.setCustomValidity(!this.value && this.query ? 'Válassz egy elemet a találati listából.' : '');
    },
    notify() { this.$dispatch(this.eventName, { value: this.value }); },
    edit() {
        const hadSelection = this.value !== '';
        this.value = '';
        this.open = true;
        this.active = -1;
        this.syncValidity();
        if (hadSelection) this.notify();
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
        this.value = ''; this.query = ''; this.active = -1; this.open = true;
        this.syncValidity(); this.notify(); this.$refs.input.focus();
    },
    move(direction) {
        this.open = true;
        if (!this.results.length) { this.active = -1; return; }
        this.active = this.active < 0 ? (direction > 0 ? 0 : this.results.length - 1)
            : (this.active + direction + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.list.children[this.active]?.scrollIntoView({ block: 'nearest' }));
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
