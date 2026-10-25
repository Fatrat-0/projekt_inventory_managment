import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('productListing', () => ({
    filtersOpen: false,
    colMenuOpen: false,
    viewMode: 'list',
    cols: { sku: true, category: true, stock: true, price: true, value: true },
    init() {
        this.filtersOpen = ['category_id', 'warehouse_id', 'stock_status'].some(key => new URLSearchParams(location.search).get(key));
        try {
            const mode = localStorage.getItem('productViewMode');
            if (['list', 'kanban'].includes(mode)) this.viewMode = mode;
            const saved = JSON.parse(localStorage.getItem('productColumns') || '{}');
            for (const key of Object.keys(this.cols)) {
                if (typeof saved?.[key] === 'boolean') this.cols[key] = saved[key];
            }
        } catch { /* Storage may be unavailable or contain outdated preferences. */ }
        this.$watch('cols', value => {
            try { localStorage.setItem('productColumns', JSON.stringify(value)); } catch {}
        });
    },
    setView(mode) {
        this.viewMode = mode;
        try { localStorage.setItem('productViewMode', mode); } catch {}
    },
}));

Alpine.start();
