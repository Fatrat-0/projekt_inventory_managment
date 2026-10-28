<?php

// Starts a disposable browser-test application. Never uses the Laragon database.
use App\Models\{Category, InventoryMovement, Product, Stock, User, Warehouse};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Artisan, DB, Hash};

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached()) throw new RuntimeException('Clear configuration cache before browser tests.');
// Guard the destructive migration by checking both the driver and the exact test file.
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database)) {
    throw new RuntimeException('Browser tests require the isolated SQLite database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
$user = User::factory()->create(['email' => 'browser@example.test', 'password' => Hash::make('browser-test-password'), 'role' => 'worker']);
$category = Category::create(['category_name' => 'Browser']);
$a = Product::create(['category_id' => $category->category_id, 'product_name' => 'Alpha teszttermék', 'sku' => 'ALPHA-001', 'price' => 100]);
$b = Product::create(['category_id' => $category->category_id, 'product_name' => 'Zeta teszttermék', 'sku' => 'ZETA-002', 'price' => 100]);
for ($i = 1; $i <= 10; $i++) {
    Product::create(['category_id' => $category->category_id, 'product_name' => 'Ékezetes teszttermék '.str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'sku' => 'DEMO-'.str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'price' => 100]);
}
$warehouse = Warehouse::create(['warehouse_name' => 'Teszt raktár', 'location' => 'Test']);
foreach (['Északi tesztraktár', 'Déli tesztraktár', 'Nyugati tesztraktár'] as $name) Warehouse::create(['warehouse_name' => $name, 'location' => 'Bemutató célállomás']);
foreach ([[$a, 20], [$b, 7]] as [$product, $quantity]) {
    Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => $quantity]);
}
for ($i = 0; $i < 30; $i++) {
    InventoryMovement::create(['product_id' => $a->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id, 'type' => 'in', 'quantity' => 1, 'reference' => 'BROWSER-'.$i]);
}
