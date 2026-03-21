<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\PartnerController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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
        Route::resource('movements', InventoryMovementController::class)->only(['index', 'create', 'store']);
        Route::resource('partners', PartnerController::class)->only(['index', 'store', 'destroy']);
    });

    // A kategóriákat és raktárakat csak az Admin és a Manager piszkálhatja
    Route::middleware(['role:admin,manager'])->group(function () {
        Route::resource('categories', CategoryController::class);
        Route::resource('warehouses', WarehouseController::class);
    });

});

require __DIR__.'/auth.php';
