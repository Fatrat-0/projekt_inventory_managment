import './bootstrap';

import Alpine from 'alpinejs';
import movementForm from './components/movement-form';
import manualMovementForm from './components/manual-movement-form';
import transferPutaway from './components/transfer-putaway';
import transferIntentCreate from './components/transfer-intent-create';
import transferIntentDecision from './components/transfer-intent-decision';
import warehousePickingTask from './components/warehouse-picking-task';
import warehouseReceiving from './components/warehouse-receiving';
import warehouseTaskTabs from './components/warehouse-task-tabs';
import warehousePutaway from './components/warehouse-putaway';
import warehouseRelocation from './components/warehouse-relocation';
import autocompletePicker from './components/autocomplete-picker';
import listingSearch from './components/listing-search';
import listingRemoteSearch from './components/listing-remote-search';
import listingSort from './components/listing-sort';
import shipmentPlanDetail from './components/shipment-plan-detail';
import shipmentPlanCreate from './components/shipment-plan-create';
import vehicleRegistry from './components/vehicle-registry';
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
Alpine.data('manualMovementForm', manualMovementForm);
Alpine.data('transferPutaway', transferPutaway);
Alpine.data('transferIntentCreate', transferIntentCreate);
Alpine.data('transferIntentDecision', transferIntentDecision);
Alpine.data('warehousePickingTask', warehousePickingTask);
Alpine.data('warehouseReceiving', warehouseReceiving);
Alpine.data('warehouseTaskTabs', warehouseTaskTabs);
Alpine.data('warehousePutaway', warehousePutaway);
Alpine.data('warehouseRelocation', warehouseRelocation);
Alpine.data('autocompletePicker', autocompletePicker);
Alpine.data('listingSearch', listingSearch);
Alpine.data('listingRemoteSearch', listingRemoteSearch);
Alpine.data('listingSort', listingSort);
Alpine.data('shipmentPlanDetail', shipmentPlanDetail);
Alpine.data('shipmentPlanCreate', shipmentPlanCreate);
Alpine.data('vehicleRegistry', vehicleRegistry);
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
