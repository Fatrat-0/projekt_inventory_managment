<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ExportController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Csak bejelentkezett felhasználóknak (auth), és beállítjuk a jogosultságokat (role)
Route::middleware(['auth'])->group(function () {

    // Breeze Profil kezelése
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Mindenki, aki be van jelentkezve (admin, manager, worker) láthatja és kezelheti a termékeket
    Route::middleware(['role:admin,manager,worker'])->group(function () {
        Route::resource('products', ProductController::class);
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/movements/stock', [InventoryMovementController::class, 'stock'])->name('movements.stock');
        Route::resource('movements', InventoryMovementController::class)->only(['index', 'create', 'store', 'show']);
        Route::resource('partners', PartnerController::class)->only(['index', 'store', 'destroy']);
        Route::resource('transfers', InventoryTransferController::class)->only(['index', 'create', 'store', 'show']);
        Route::resource('shipments', \App\Http\Controllers\ShipmentController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
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
        Route::resource('categories', CategoryController::class);
        Route::resource('warehouses', WarehouseController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show', 'edit', 'update']);
    });

});

require __DIR__.'/auth.php';
