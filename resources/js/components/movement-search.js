export default (initial) => ({
    items: initial.items,
    query: initial.query ?? '',
    open: false,
    active: -1,
    normalize(text) {
        return text.toLocaleLowerCase('hu').normalize('NFD').replace(/\p{M}/gu, '');
    },
    get matches() {
        const words = this.normalize(this.query).trim().split(/\s+/).filter(Boolean);
        if (!words.length) return [];
        return this.items.filter(item => {
            const text = this.normalize(item.name + ' ' + item.sku);
            return words.every(word => text.includes(word));
        });
    },
    get results() { return this.matches.slice(0, 8); },
    edit() { this.active = -1; this.open = this.query.trim() !== ''; },
    move(direction) {
        if (!this.results.length) return;
        this.open = true;
        this.active = this.active < 0 ? (direction > 0 ? 0 : this.results.length - 1)
            : (this.active + direction + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.list.children[this.active]?.scrollIntoView({ block: 'nearest' }));
    },
    enter(event) {
        if (event.isComposing || event.keyCode === 229) return;
        if (this.open && this.active >= 0) {
            event.preventDefault();
            this.choose(this.results[this.active]);
        } else {
            // Enter without an explicit suggestion keeps the existing free-text search.
            this.open = false;
        }
    },
    choose(item) {
        if (!item) return;
        // Read current form fields, including controls associated by the form attribute.
        // Selecting a suggestion replaces both the free text and any previous product filter.
        const form = this.$refs.input.form;
        const url = new URL(form.action);
        for (const [key, value] of new FormData(form)) url.searchParams.set(key, value);
        url.searchParams.set('product_id', item.value);
        url.searchParams.delete('search');
        url.searchParams.delete('page');
        this.open = false;
        window.location.assign(url);
    },
});
