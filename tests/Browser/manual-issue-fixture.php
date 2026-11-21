<?php

use App\Models\{Category, InventoryMovement, Partner, Product, Stock, Warehouse};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database)) {
    throw new RuntimeException('Manual issue fixtures require the isolated browser database.');
}

if (($argv[1] ?? '') === 'remove-wide') {
    $stock = Stock::with(['product', 'warehouse'])->findOrFail($argv[2]);
    $partner = Partner::findOrFail($argv[3]);
    if (!str_starts_with($stock->product->product_name, 'Kiadási próbatermék Hosszúmegnevezés')
        || !str_starts_with($stock->warehouse->warehouse_name, 'Kiadási próbaraktár Hosszúmegnevezés')
        || !str_starts_with($partner->name, 'Elrendezési próbavevő Hosszúmegnevezés')) {
        throw new RuntimeException('Only the long-name browser fixture may be removed.');
    }
    DB::transaction(function () use ($stock, $partner): void {
        $stock->placements()->delete();
        $stock->delete();
        $stock->warehouse->storageLocations()->delete();
        $stock->warehouse->delete();
        $stock->product->delete();
        $partner->delete();
    });
    return;
}

if (ctype_digit($argv[1] ?? '')) {
    $stock = Stock::findOrFail($argv[1]);
    echo json_encode(['quantity' => $stock->quantity, 'bins' => $stock->placements()->orderBy('warehouse_location_id')->pluck('quantity', 'warehouse_location_id'),
        'movements' => InventoryMovement::where('product_id', $stock->product_id)->where('warehouse_id', $stock->warehouse_id)
            ->where('type', 'out')->with('issueSources')->get()], JSON_THROW_ON_ERROR);
    return;
}

$scenario = $argv[1] ?? '';
$wide = $scenario === 'wide';
$suffix = $wide ? ' '.str_repeat('Hosszúmegnevezés', 4) : '';
$product = Product::factory()->create(['product_name' => 'Kiadási próbatermék'.$suffix, 'category_id' => Category::firstOrFail()->getKey()]);
$product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 10]);
$warehouse = Warehouse::factory()->create(['warehouse_name' => $scenario === 'options' ? 'Kiadási szűrőpróba' : 'Kiadási próbaraktár'.$suffix,
    'location' => "Fő utca 12.\nTeszttelep"]);
$stock = Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 65]);
$partner = $wide ? Partner::create(['name' => 'Elrendezési próbavevő'.$suffix, 'type' => 'customer']) : null;
$ids = [];
$contents = match ($scenario) {
    'wide' => ['A-01' => 10, 'A-02' => 10, 'A-03' => 10, 'A-04' => 10, 'A-05' => 10],
    'loose' => ['Z-01' => 5],
    default => ['A-01' => 20, 'A-02' => 20, 'Z-01' => 5],
};
foreach ($contents as $code => $quantity) {
    $bin = $warehouse->storageLocations()->create(['code' => $code, 'name' => 'Próbapolc '.$code, 'kind' => 'shelf',
        'active' => $code !== 'Z-01', 'max_weight_grams' => 100000]);
    if ($code === 'A-01') $bin->forceFill(['floor_x' => 1, 'floor_y' => 1, 'floor_width' => 1, 'floor_height' => 1])->save();
    $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => 1000,
        'load_requires_verification' => $code === 'A-01']);
    $ids[$code] = $bin->id;
}
echo json_encode(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'stock' => $stock->id, 'bins' => $ids,
    'product' => $product->product_name, 'sku' => $product->sku, 'warehouse' => $warehouse->warehouse_name, 'partner' => $partner?->name,
    'partner_id' => $partner?->id], JSON_THROW_ON_ERROR);
