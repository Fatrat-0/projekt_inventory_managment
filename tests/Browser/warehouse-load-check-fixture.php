<?php

use App\Models\{Category, Product, Stock, User, Warehouse, WarehousePlacement};
use App\Services\{InventoryService, WarehouseStorageDesigner};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$admin = User::where('email', 'warehouse-admin@example.test')->sole();
if ($mode !== 'create') {
    $row = WarehousePlacement::findOrFail((int) ($argv[2] ?? 0));
    if ($mode === 'issue') {
        $stock = $row->stock;
        $quantity = ($argv[3] ?? '') === 'all' ? $row->quantity : 1;
        app(InventoryService::class)->recordMovement([
            'type' => 'out', 'warehouse_id' => $stock->warehouse_id, 'product_id' => $stock->product_id,
            'quantity' => $quantity, 'user_id' => $admin->id,
            'source_allocations' => [['source_type' => 'location', 'location_id' => $row->warehouse_location_id, 'quantity' => $quantity]],
        ], (string) Str::uuid());
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    throw new RuntimeException('Unknown isolated browser fixture mode.');
}

$key = strtoupper(bin2hex(random_bytes(3)));
$warehouse = Warehouse::factory()->create(['warehouse_name' => 'Terhelésmérési raktár '.$key]);
$foreign = Warehouse::factory()->create(['warehouse_name' => 'Másik tesztraktár '.$key]);
$category = Category::factory()->create();
$product = Product::factory()->create(['category_id' => $category->getKey(), 'sku' => 'MER-'.$key,
    'product_name' => 'Ellenőrzendő hosszú magyar terméknév '.$key.str_repeat('megerősítettcsomagolás', 2)]);
$other = Product::factory()->create(['category_id' => $category->getKey(), 'sku' => 'MAS-'.$key, 'product_name' => 'Második tétel '.$key]);
$exact = ($argv[2] ?? '') === 'exact';
$system = app(WarehouseStorageDesigner::class)->create($warehouse, [
    'version' => $warehouse->refresh()->floor_version, 'kind' => 'rack', 'code' => 'MERES-'.$key,
    'name' => 'Ellenőrzési állvány', 'zone' => 'Mérési zóna', 'width' => 2, 'height' => 2, 'rack_height' => 2,
    'x' => 0, 'y' => 0, 'levels' => 2, 'bins' => 2, 'bin_weight' => $exact ? 25 : 12, 'level_weight' => $exact ? 100 : 12, 'system_weight' => $exact ? 120 : 12,
]);
$bin = $warehouse->storageSystems()->findOrFail($system['id'])->locations()->orderBy('code')->firstOrFail();
$place = function (Product $product, int $quantity, int $grams) use ($warehouse, $bin): WarehousePlacement {
    $stock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity + 2]);

    return $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $grams,
        'pallet_slots' => 0, 'load_requires_verification' => true]);
};
$first = $place($product, 6, 10000);
$second = $place($other, 2, $exact ? 16000 : 2000);
if ($exact) {
    $adjacent = $bin->level->locations()->whereKeyNot($bin->id)->firstOrFail();
    $second->update(['warehouse_location_id' => $adjacent->id, 'load_requires_verification' => false]);
}
$safeBin = $warehouse->storageLocations()->create(['code' => 'BIZ-'.$key, 'name' => 'Független célrekesz',
    'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 30000]);
if (($argv[2] ?? '') === 'duplicates') {
    $stock = $first->stock;
    $stock->increment('quantity', 3);
    $safeBin->placements()->create(['stock_id' => $stock->id, 'quantity' => 3, 'cargo_weight_grams' => 3000,
        'pallet_slots' => 0, 'load_requires_verification' => true]);
}
$operator = User::factory()->create(['role' => 'worker', 'email' => 'load-'.strtolower($key).'@example.test',
    'password' => Hash::make('browser-test-password')]);
$operator->addWarehouseMembership($warehouse->getKey(), ['dispatch_operator']);
$args = [$warehouse, $first->id];
echo json_encode([
    'id' => $first->id, 'other_id' => $second->id, 'sku' => $product->sku, 'other_sku' => $other->sku,
    'product' => $product->product_name, 'warehouse' => $warehouse->warehouse_name, 'foreign' => $foreign->warehouse_name,
    'foreign_id' => $foreign->getKey(), 'code' => $bin->code, 'operator' => $operator->email,
    'index' => route('warehouses.load-checks.index', $warehouse, false),
    'show' => route('warehouses.load-checks.show', $args, false),
    'bin' => route('warehouses.locations.show', [$warehouse, $bin], false),
    'api' => route('warehouse-load-verifications.show', $args, false),
    'preview' => route('warehouse-load-verifications.preview', $args, false),
    'other_api' => route('warehouse-load-verifications.show', [$warehouse, $second->id], false),
    'product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'source_id' => $bin->id,
    'safe_id' => $safeBin->id, 'safe_code' => $safeBin->code,
    'place' => route('warehouses.locations.place', [$warehouse, $bin], false),
    'relocate' => route('warehouse-relocations.store', [], false),
    'history' => route('warehouse-load-verifications.history', ['warehouse' => $warehouse, 'placement_id' => $first->id], false),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
