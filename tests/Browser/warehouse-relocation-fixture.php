<?php

use App\Models\{Category, Product, Stock, Warehouse, WarehouseStorageSystem, WarehouseStorageZone};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database)) {
    throw new RuntimeException('Relocation fixtures require the isolated browser database.');
}

$key = $argv[1];
$category = Category::firstOrFail();
$product = Product::factory()->create(['sku' => 'REK-PROBA-'.$key, 'category_id' => $category->getKey(),
    'product_name' => 'Csavar '.$key.' '.str_repeat('hosszú terméknév ', 4)]);
$loose = Product::factory()->create(['sku' => 'REK-LAZA-'.$key, 'category_id' => $category->getKey()]);
$warehouse = Warehouse::factory()->create(['warehouse_name' => 'Áthelyezési próbaterület '.$key.' '.str_repeat('hosszú raktárnév ', 3)]);
$other = Warehouse::factory()->create(['warehouse_name' => 'Külső próbaterület '.$key]);
$product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 10]);
$stock = Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 100]);
Stock::create(['product_id' => $loose->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 9]);
Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $other->getKey(), 'quantity' => 9]);

$bins = [];
foreach (['A-01' => 100000, 'B-01' => 25000, 'B-02' => 500, 'C-01' => 200000] as $i => $cap) {
    $location = $warehouse->storageLocations()->make();
    $location->forceFill(['code' => $i, 'name' => 'Rekesz '.$i, 'kind' => 'shelf', 'active' => true,
        'max_weight_grams' => $cap, 'width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100,
        'floor_x' => 1 + count($bins) * 2, 'floor_y' => 1, 'floor_width' => 1, 'floor_height' => 1])->save();
    $bins[$i] = $location;
}
$bins['A-01']->placements()->create(['stock_id' => $stock->id, 'quantity' => 40, 'cargo_weight_grams' => 12000]);
$additional = null;
if ($key === 'TOBB') {
    $additional = Product::factory()->create(['sku' => 'REK-MASIK-'.$key, 'category_id' => $category->getKey(),
        'product_name' => 'Második termék ugyanabban a rekeszben']);
    $additionalStock = Stock::create(['product_id' => $additional->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 12]);
    $zone = WarehouseStorageZone::create(['warehouse_id' => $warehouse->getKey(), 'name' => 'Északi tárolózóna']);
    $rack = WarehouseStorageSystem::create(['zone_id' => $zone->id, 'name' => 'Hosszú nevű bemutató polcos állvány',
        'code' => 'DEM-01', 'kind' => 'rack', 'max_weight_grams' => 200000, 'height_cm' => 200,
        'floor_x' => 1, 'floor_y' => 1, 'floor_width' => 3, 'floor_height' => 1]);
    $level = $rack->levels()->create(['number' => 1, 'max_weight_grams' => 150000]);
    foreach (['A-01' => 1, 'C-01' => 2] as $code => $number) {
        $bins[$code]->forceFill(['system_id' => $rack->id, 'level_id' => $level->id, 'bin_number' => $number])->save();
    }
    $bins['A-01']->placements()->create(['stock_id' => $additionalStock->id, 'quantity' => 7, 'cargo_weight_grams' => 2000]);
    $bins['C-01']->placements()->create(['stock_id' => $additionalStock->id, 'quantity' => 3, 'cargo_weight_grams' => 1000]);
}
if (in_array($key, ['STALE', 'KESOI'], true)) {
    $quantity = $key === 'STALE' ? 20 : 6;
    $bins['C-01']->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $quantity * 300]);
}
echo json_encode(['warehouse_id' => $warehouse->getKey(), 'warehouse_name' => $warehouse->warehouse_name,
    'product_id' => $product->getKey(), 'sku' => $product->sku, 'loose_sku' => $loose->sku,
    'additional_sku' => $additional?->sku, 'remote_name' => $other->warehouse_name, 'locations' => collect($bins)->map(fn ($bin) => $bin->id)], JSON_THROW_ON_ERROR);
