<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseFloorPlanController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\TransferIntentController;
use App\Http\Controllers\TransferIntentPageController;
use App\Http\Controllers\TransferStockReservationController;
use App\Http\Controllers\WarehouseTransferTaskController;
use App\Http\Controllers\WarehousePickingPageController;
use App\Http\Controllers\WarehouseTaskCountsController;
use App\Http\Controllers\ShipmentPlanningController;
use App\Http\Controllers\ShipmentReceivingController;
use App\Http\Controllers\WarehouseReceivingPageController;
use App\Http\Controllers\ShipmentReceiptPutawayController;
use App\Http\Controllers\ShipmentPlanPageController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ExportController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/search', [\App\Http\Controllers\AppSearchController::class, 'index'])->name('app-search.index');
    Route::get('/dashboard/customize', [\App\Http\Controllers\DashboardLayoutController::class, 'edit'])->name('dashboard.customize');
    Route::patch('/dashboard/layout', [\App\Http\Controllers\DashboardLayoutController::class, 'update'])->name('dashboard.layout.update');
});

// Csak bejelentkezett felhasználóknak (auth), és beállítjuk a jogosultságokat (role)
Route::middleware(['auth'])->group(function () {

    Route::prefix('shipment-plans')->name('shipment-plans.')->controller(ShipmentPlanningController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/eligible-intents', 'eligible')->name('eligible');
        Route::post('/', 'store')->name('store');
        Route::get('/receiving', [ShipmentReceivingController::class, 'index'])->name('receiving.index');
        Route::get('/receiving/pending-putaway', [ShipmentReceivingController::class, 'pendingPutaway'])->name('receiving.pending-putaway');
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle')->name('vehicles.update');
        Route::get('/vehicles/{vehicle}/shipments', [VehicleController::class, 'relatedShipments'])->whereNumber('vehicle')->name('vehicles.shipments');
        Route::where(['shipment' => '[0-9]+'])->group(function () {
            Route::get('/{shipment}', 'show')->name('show');
            Route::post('/{shipment}/intents', 'attach')->name('intents.attach');
            Route::delete('/{shipment}/intents/{transferIntent}', 'detach')->whereNumber('transferIntent')->name('intents.detach');
            Route::get('/{shipment}/stops', 'stops')->name('stops.show');
            Route::patch('/{shipment}/stops', 'reorder')->name('stops.update');
            Route::get('/{shipment}/stops/{stop}/receiving', [ShipmentReceivingController::class, 'show'])->whereNumber('stop')->name('stops.receiving.show');
            Route::get('/{shipment}/stops/{stop}/receipts', [ShipmentReceivingController::class, 'history'])->whereNumber('stop')->name('stops.receipts.index');
            Route::post('/{shipment}/stops/{stop}/receipts', [ShipmentReceivingController::class, 'store'])->whereNumber('stop')->name('stops.receipts.store');
            Route::get('/{shipment}/loading-order', 'loadingOrder')->name('loading-order');
            Route::get('/{shipment}/readiness', 'readiness')->name('readiness');
            Route::get('/{shipment}/cargo', 'cargo')->name('cargo.show');
            Route::patch('/{shipment}/vehicle', 'selectVehicle')->name('vehicle.update');
            Route::patch('/{shipment}/cargo/items/{assignment}', 'saveCargoItem')->whereNumber('assignment')->name('cargo.items.update');
            Route::patch('/{shipment}/cargo', 'confirmCargo')->name('cargo.confirm');
            Route::get('/{shipment}/dispatch', 'dispatchState')->name('dispatch.show');
            Route::post('/{shipment}/dispatch', 'dispatch')->name('dispatch.store');
        });
    });

    Route::prefix('shipment-receipt-items/{item}/putaway')->name('shipment-receipt-items.putaway.')
        ->where(['item' => '[0-9]+'])->controller(ShipmentReceiptPutawayController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::get('/history', 'history')->name('history');
            Route::post('/recommendations', 'recommendations')->name('recommendations');
            Route::post('/locations', 'locations')->name('locations');
            Route::post('/', 'store')->name('store');
        });

    Route::get('/shipments', [ShipmentPlanPageController::class, 'index'])->name('shipments.index');
    Route::get('/shipments/create', [ShipmentPlanPageController::class, 'create'])->name('shipments.create');
    Route::get('/shipments/{shipment}', [ShipmentPlanPageController::class, 'show'])->whereNumber('shipment')->name('shipments.show');

    Route::get('/atmozgatasok', [TransferIntentPageController::class, 'index'])->name('transfer-intent-pages.index');
    Route::get('/atmozgatasok/jarmuvek', [VehicleController::class, 'page'])->name('vehicle-pages.index');
    Route::get('/atmozgatasok/uj-igeny', [TransferIntentPageController::class, 'create'])->name('transfer-intent-pages.create');
    Route::get('/atmozgatasok/igenyek/{transferIntent}', [TransferIntentPageController::class, 'show'])->whereNumber('transferIntent')->name('transfer-intent-pages.show');
    Route::prefix('/atmozgatasok/raktari-feladatok')->controller(WarehouseReceivingPageController::class)->group(function () {
        Route::get('/atvetel', 'index')->name('warehouse-receiving-pages.index');
        Route::get('/atvetel/{shipment}/{stop}', 'show')->whereNumber(['shipment', 'stop'])->name('warehouse-receiving-pages.show');
        Route::get('/betarolas', 'putawayIndex')->name('warehouse-putaway-pages.index');
        Route::get('/betarolas/{item}', 'putaway')->whereNumber('item')->name('warehouse-putaway-pages.show');
        Route::get('/betarolas/{item}/alaprajz/{warehouseLocation}', [WarehouseFloorPlanController::class, 'putaway'])
            ->whereNumber(['item', 'warehouseLocation'])->name('warehouse-putaway-pages.plan');
    });


    Route::get('/atmozgatasok/raktari-feladatok/szamlalok', WarehouseTaskCountsController::class)->name('warehouse-task-counts');
    Route::get('/atmozgatasok/raktari-feladatok', [WarehousePickingPageController::class, 'index'])->name('warehouse-picking-pages.index');
    Route::get('/atmozgatasok/raktari-feladatok/{transferIntent}', [WarehousePickingPageController::class, 'show'])
        ->whereNumber('transferIntent')->can('viewPickingTask', 'transferIntent')->name('warehouse-picking-pages.show');
    Route::get('/atmozgatasok/raktari-feladatok/{transferIntent}/alaprajz/{warehouseLocation}', [WarehouseFloorPlanController::class, 'picking'])
        ->whereNumber(['transferIntent', 'warehouseLocation'])->can('viewPickingTask', 'transferIntent')->name('warehouse-picking-pages.plan');

    Route::resource('transfer-intents', TransferIntentController::class)
        ->parameters(['transfer-intents' => 'transferIntent'])->only(['index', 'store', 'show']);
    Route::patch('/transfer-intents/{transferIntent}/approve', [TransferIntentController::class, 'approve'])->name('transfer-intents.approve');
    Route::patch('/transfer-intents/{transferIntent}/reject', [TransferIntentController::class, 'reject'])->name('transfer-intents.reject');
    Route::patch('/transfer-intents/{transferIntent}/cancel', [TransferIntentController::class, 'cancel'])->name('transfer-intents.cancel');
    Route::get('/transfer-intents/{transferIntent}/stock-reservation', [TransferStockReservationController::class, 'show'])
        ->whereNumber('transferIntent')->name('transfer-intents.stock-reservation.show');
    Route::post('/transfer-intents/{transferIntent}/stock-reservation', [TransferStockReservationController::class, 'store'])
        ->whereNumber('transferIntent')->can('reserveStock', 'transferIntent')->name('transfer-intents.stock-reservation.store');
    Route::patch('/transfer-intents/{transferIntent}/stock-reservation/release', [TransferStockReservationController::class, 'release'])
        ->whereNumber('transferIntent')->can('releaseStock', 'transferIntent')->name('transfer-intents.stock-reservation.release');
    Route::get('/transfer-intents/{transferIntent}/picking-task', [WarehouseTransferTaskController::class, 'show'])
        ->whereNumber('transferIntent')->can('viewPickingTask', 'transferIntent')->name('transfer-intents.picking-task.show');
    Route::post('/transfer-intents/{transferIntent}/picking-task', [WarehouseTransferTaskController::class, 'store'])
        ->whereNumber('transferIntent')->can('createPickingTask', 'transferIntent')->name('transfer-intents.picking-task.store');
    Route::get('/transfer-intents/{transferIntent}/picking-task/recommendations', [WarehouseTransferTaskController::class, 'recommendations'])
        ->whereNumber('transferIntent')->can('viewPickingTask', 'transferIntent')->name('transfer-intents.picking-task.recommendations');
    Route::post('/transfer-intents/{transferIntent}/picking-task/picks', [WarehouseTransferTaskController::class, 'pick'])
        ->whereNumber('transferIntent')->can('executePickingTask', 'transferIntent')->name('transfer-intents.picking-task.picks');

    // Breeze Profil kezelése
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Mindenki, aki be van jelentkezve (admin, manager, worker) láthatja és kezelheti a termékeket
    Route::middleware(['role:'.implode(',', \App\Http\Middleware\RoleMiddleware::INVENTORY_ROLES)])->group(function () {
        Route::resource('products', ProductController::class);
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/warehouses/{warehouse}/floor-plan', [WarehouseFloorPlanController::class, 'show'])->name('warehouses.floor-plan.show');
        Route::get('/warehouses/{warehouse}/locations', [\App\Http\Controllers\WarehouseLocationController::class, 'index'])->name('warehouses.locations.index');
        Route::get('/warehouses/{warehouse}/storage-systems/{system}', [\App\Http\Controllers\WarehouseStorageSystemController::class, 'show'])->whereNumber('system')->name('warehouses.storage-systems.show');
        Route::get('/warehouses/{warehouse}/storage-systems/{system}/capacity', [\App\Http\Controllers\WarehouseStorageSystemController::class, 'capacity'])->whereNumber('system')->name('warehouses.storage-systems.capacity');
        Route::get('/warehouses/{warehouse}/locations/{warehouseLocation}', [\App\Http\Controllers\WarehouseLocationController::class, 'show'])->name('warehouses.locations.show');
        Route::post('/warehouses/{warehouse}/locations/{warehouseLocation}/placements', [\App\Http\Controllers\WarehouseLocationController::class, 'place'])->name('warehouses.locations.place');
        Route::delete('/warehouses/{warehouse}/locations/{warehouseLocation}/placements/{placement}', [\App\Http\Controllers\WarehouseLocationController::class, 'release'])->name('warehouses.locations.release');
        Route::get('/quality-returns', [\App\Http\Controllers\ReturnQualityQueueController::class, 'index'])->name('quality-returns.index');
        Route::get('/movements/stock', [InventoryMovementController::class, 'stock'])->name('movements.stock');
        Route::get('/movements/{movement}/return', [\App\Http\Controllers\MovementReturnController::class, 'create'])->name('movements.return.create');
        Route::post('/movements/{movement}/return', [\App\Http\Controllers\MovementReturnController::class, 'store'])->name('movements.return.store');
        Route::post('/movements/{movement}/quality', [\App\Http\Controllers\ReturnQualityController::class, 'store'])->name('movements.quality.store');
        Route::resource('movements', InventoryMovementController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/partners/duplicates', [PartnerController::class, 'duplicates'])->middleware('throttle:60,1')->name('partners.duplicates');
        Route::get('/partners/{partner}/duplicates', [PartnerController::class, 'duplicates'])->middleware('throttle:60,1')->name('partners.duplicates-edit');
        Route::patch('/partners/{partner}/archive', [PartnerController::class, 'archive'])->name('partners.archive');
        Route::patch('/partners/{partner}/restore', [PartnerController::class, 'restore'])->name('partners.restore');
        Route::resource('partners', PartnerController::class)->except(['show']);
        Route::get('/transfers/putaway-recommendations', [InventoryTransferController::class, 'putawayRecommendations'])->name('transfers.putaway-recommendations');
        Route::get('/transfers/picking-sources', [InventoryTransferController::class, 'pickingSources'])->name('transfers.picking-sources');
        Route::resource('transfers', InventoryTransferController::class)->only(['index', 'create', 'store', 'show']);
        Route::resource('shipments', \App\Http\Controllers\ShipmentController::class)->only(['store', 'update']);
        Route::get('/shipments/{shipment}/edit', [ShipmentPlanPageController::class, 'edit'])->whereNumber('shipment')->name('shipments.edit');
        Route::post('/shipments/{shipment}/start', [\App\Http\Controllers\ShipmentController::class, 'start'])->name('shipments.start');
        Route::patch('/shipments/{shipment}/stops/{stop}/receive', [\App\Http\Controllers\ShipmentController::class, 'receive'])->name('shipments.receive');
        Route::patch('/shipments/{shipment}/cancel', [\App\Http\Controllers\ShipmentController::class, 'cancel'])->name('shipments.cancel');
        Route::get('/shipments/{shipment}/loading-plan', [\App\Http\Controllers\ShipmentController::class, 'pdf'])->name('shipments.pdf');
        Route::patch('/transfers/{transfer}/complete', [InventoryTransferController::class, 'complete'])->name('transfers.complete');
        Route::patch('/transfers/{transfer}/cancel', [InventoryTransferController::class, 'cancel'])->name('transfers.cancel');
        Route::get('/export-inventory', [ExportController::class, 'inventoryPdf'])->name('export.inventory');
        Route::get('/export-movements', [ExportController::class, 'movementsPdf'])->name('export.movements');
        Route::get('/transfers/{transfer}/pdf', [ExportController::class, 'transferPdf'])->name('export.transfer');
    });

    // A kategóriákat és raktárakat csak az Admin és a Manager piszkálhatja
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::patch('/stock/{stock}/minimum', [StockController::class, 'minimum'])->name('stock.minimum');
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::get('/warehouses/statistics', [WarehouseController::class, 'statistics'])->name('warehouses.statistics');
        Route::get('/warehouses/statistics.pdf', [ExportController::class, 'warehouseStatisticsPdf'])->name('warehouses.statistics-pdf');
        Route::resource('warehouses', WarehouseController::class);
        Route::put('/warehouses/{warehouse}/floor-plan', [WarehouseFloorPlanController::class, 'update'])->name('warehouses.floor-plan.update');
        Route::post('/warehouses/{warehouse}/floor-plan/systems', [WarehouseFloorPlanController::class, 'storeSystem'])->name('warehouses.floor-plan.systems.store');
        Route::patch('/warehouses/{warehouse}/floor-plan/systems/{system}', [WarehouseFloorPlanController::class, 'updateSystem'])->whereNumber('system')->name('warehouses.floor-plan.systems.update');
        Route::patch('/warehouses/{warehouse}/storage-systems/{system}/bins/{bin}', [\App\Http\Controllers\WarehouseStorageSystemController::class, 'updateBin'])->whereNumber('system')->whereNumber('bin')->name('warehouses.storage-systems.bins.update');
        Route::post('/warehouses/{warehouse}/locations', [\App\Http\Controllers\WarehouseLocationController::class, 'store'])->name('warehouses.locations.store');
        Route::patch('/warehouses/{warehouse}/locations/{warehouseLocation}', [\App\Http\Controllers\WarehouseLocationController::class, 'update'])->name('warehouses.locations.update');
        Route::get('/warehouses/{warehouse}/inventory-preview', [WarehouseController::class, 'inventoryPreview'])->name('warehouses.inventory-preview');
        Route::get('/warehouses/{warehouse}/inventory.pdf', [ExportController::class, 'warehouseInventoryPdf'])->name('warehouses.inventory-pdf');
        Route::resource('users', UserController::class)->except(['show', 'edit', 'update']);
    });

});

require __DIR__.'/auth.php';
