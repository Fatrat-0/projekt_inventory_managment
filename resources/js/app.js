import './bootstrap';

import Alpine from 'alpinejs';
import movementForm from './components/movement-form';
import autocompletePicker from './components/autocomplete-picker';
import listingSearch from './components/listing-search';
import shipmentBuilder from './components/shipment-builder';
import pdfPreview from './components/pdf-preview';
import rowActions from './components/row-actions';
import partnerForm from './components/partner-form';
import movementPartnerPicker from './components/movement-partner-picker';
import dashboardList from './components/dashboard-list';
import activityChart from './components/activity-chart';
import dashboardView from './components/dashboard-view';
import dashboardCustomization from './components/dashboard-customization';
import appShell from './components/app-shell';
import appSearch from './components/app-search';
import stockListing from './components/stock-listing';
import listingView from './components/listing-view';
import listingColumns from './components/listing-columns';
import warehouseFloorPlan from './components/warehouse-floor-plan';
import warehouseCapacityTree from './components/warehouse-capacity-tree';

window.Alpine = Alpine;

Alpine.data('listingView', listingView);
Alpine.data('listingColumns', listingColumns);

Alpine.data('productListing', () => ({
    ...listingView('products'),
    filtersOpen: false,
    init() {
        listingView('products').init.call(this);
        this.filtersOpen = ['category_id', 'warehouse_id', 'stock_status'].some(key => new URLSearchParams(location.search).get(key));
    },
}));

Alpine.data('movementForm', movementForm);
Alpine.data('autocompletePicker', autocompletePicker);
Alpine.data('listingSearch', listingSearch);
Alpine.data('shipmentBuilder', shipmentBuilder);
Alpine.data('pdfPreview', pdfPreview);
Alpine.data('rowActions', rowActions);
Alpine.data('partnerForm', partnerForm);
Alpine.data('movementPartnerPicker', movementPartnerPicker);
Alpine.data('dashboardList', dashboardList);
Alpine.data('activityChart', activityChart);
Alpine.data('dashboardView', dashboardView);
Alpine.data('dashboardCustomization', dashboardCustomization);
Alpine.data('appShell', appShell);
Alpine.data('appSearch', appSearch);
Alpine.data('stockListing', stockListing);
Alpine.data('warehouseFloorPlan', warehouseFloorPlan);
Alpine.data('warehouseCapacityTree', warehouseCapacityTree);

Alpine.start();
