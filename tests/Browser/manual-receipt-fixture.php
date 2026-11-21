<?php

use App\Models\{Category, Product, Stock, Warehouse};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database)) {
    throw new RuntimeException('Manual receipt fixtures require the isolated browser database.');
}

$key = $argv[1];
$product = Product::factory()->create([
    'sku' => 'KEZI-IN-'.$key, 'category_id' => Category::firstOrFail()->getKey(),
    'product_name' => 'Kézi bevételezési próba '.$key.' '.str_repeat('Hosszú terméknév ', 3),
]);
$warehouse = Warehouse::factory()->create(['warehouse_name' => 'Bevételezési raktár '.$key.' '.str_repeat('Hosszú telephelynév ', 2)]);
$product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 10, 'gross_weight_kg' => '4']);
if ($key === 'RETRY') Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 50]);
$bins = [];
foreach (['A-01', 'A-02'] as $index => $code) {
    $location = $warehouse->storageLocations()->make();
    $location->forceFill([
        'code' => $code, 'name' => 'Rekesz '.$code, 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 200000,
        'width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100,
        'floor_x' => 1 + $index * 2, 'floor_y' => 1, 'floor_width' => 1, 'floor_height' => 1,
    ])->save();
    $bins[$code] = $location->id;
}
echo json_encode(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'sku' => $product->sku, 'bins' => $bins], JSON_THROW_ON_ERROR);
