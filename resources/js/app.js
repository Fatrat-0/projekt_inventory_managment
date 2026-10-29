import './bootstrap';

import Alpine from 'alpinejs';
import movementForm from './components/movement-form';
import autocompletePicker from './components/autocomplete-picker';
import listingSearch from './components/listing-search';
import shipmentBuilder from './components/shipment-builder';
import pdfPreview from './components/pdf-preview';
import rowActions from './components/row-actions';

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

Alpine.data('movementForm', movementForm);
Alpine.data('autocompletePicker', autocompletePicker);
Alpine.data('listingSearch', listingSearch);
Alpine.data('shipmentBuilder', shipmentBuilder);
Alpine.data('pdfPreview', pdfPreview);
Alpine.data('rowActions', rowActions);

Alpine.start();
