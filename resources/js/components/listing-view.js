export default (page, state = {}) => ({
    viewMode: 'table',
    defaultView: 'table',
    cols: {},
    columnOptions: [],
    init() {
        this.viewMode = this.defaultView;
        try {
            let saved = localStorage.getItem(`inventory.${page}.view`);
            if (saved === null && page === 'products') {
                saved = { list: 'table', kanban: 'cards' }[localStorage.getItem('productViewMode')];
            }
            if (['table', 'cards'].includes(saved)) this.viewMode = saved;
        } catch { /* Browsing remains usable when storage is unavailable. */ }
    },
    configureColumns(options) {
        this.columnOptions = options;
        const cols = Object.fromEntries(options.map(option => [option.key, true]));
        try {
            const raw = localStorage.getItem(`inventory.${page}.columns`)
                ?? (page === 'products' ? localStorage.getItem('productColumns') : null);
            const saved = JSON.parse(raw ?? '{}');
            if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
                for (const option of options) {
                    if (!option.required && typeof saved[option.key] === 'boolean') cols[option.key] = saved[option.key];
                }
            }
        } catch { /* Ignore blocked storage and obsolete or damaged preferences. */ }
        this.cols = cols;
        this.$watch('cols', value => {
            try { localStorage.setItem(`inventory.${page}.columns`, JSON.stringify(value)); } catch {}
        });
    },
    columnVisible(key) { return this.cols[key] !== false; },
    visibleColumnCount() { return this.columnOptions.filter(option => this.columnVisible(option.key)).length; },
    setColumn(key, visible) {
        const option = this.columnOptions.find(option => option.key === key);
        if (option && !option.required && typeof visible === 'boolean') this.cols[key] = visible;
    },
    resetColumns() { this.cols = Object.fromEntries(this.columnOptions.map(option => [option.key, true])); },
    closeMenus() {
        this.$root.querySelectorAll('[popover]').forEach(menu => {
            if (menu.matches(':popover-open')) menu.hidePopover();
        });
    },
    setView(mode) {
        if (!['table', 'cards'].includes(mode)) return;
        this.closeMenus();
        this.viewMode = mode;
        try { localStorage.setItem(`inventory.${page}.view`, mode); } catch {}
    },
    ...state,
});
