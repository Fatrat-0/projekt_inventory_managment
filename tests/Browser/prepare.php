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
// Migrate only the empty database allocated by the browser test runner.
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database) || filesize($database) !== 0) {
    throw new RuntimeException('Browser tests require the isolated SQLite database.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Browser test migrations failed: '.Artisan::output());
}
$user = User::factory()->create(['email' => 'browser@example.test', 'password' => Hash::make('browser-test-password'), 'role' => 'worker']);
User::factory()->create(['email' => 'warehouse-admin@example.test', 'password' => Hash::make('browser-test-password'), 'role' => 'admin']);
$category = Category::create(['category_name' => 'Browser']);
$a = Product::create(['category_id' => $category->category_id, 'product_name' => 'Alpha teszttermék', 'sku' => 'ALPHA-001', 'price' => 100]);
$b = Product::create(['category_id' => $category->category_id, 'product_name' => 'Zeta teszttermék', 'sku' => 'ZETA-002', 'price' => 100]);
for ($i = 1; $i <= 10; $i++) {
    Product::create(['category_id' => $category->category_id, 'product_name' => 'Ékezetes teszttermék '.str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'sku' => 'DEMO-'.str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'price' => 100]);
}
$warehouse = Warehouse::create(['warehouse_name' => 'Teszt raktár', 'location' => 'Test']);
$north = Warehouse::create(['warehouse_name' => 'Északi tesztraktár', 'location' => 'Bemutató célállomás']);
foreach (['Déli tesztraktár', 'Nyugati tesztraktár'] as $name) Warehouse::create(['warehouse_name' => $name, 'location' => 'Bemutató célállomás']);
$user->addWarehouseMembership($warehouse->getKey(), ['requester']);
$manualOperator = User::factory()->create(['email' => 'manual-issue@example.test', 'password' => Hash::make('browser-test-password'), 'role' => 'worker']);
$manualOperator->addWarehouseMembership($warehouse->getKey(), ['dispatch_operator']);
$manualOperator->addWarehouseMembership($north->getKey(), ['dispatch_operator']);
$receivingUser = User::factory()->create(['name' => 'Átvevő tesztfelhasználó', 'email' => 'receiving-intents@example.test',
    'password' => Hash::make('browser-test-password'), 'role' => 'worker']);
$receivingUser->addWarehouseMembership($north->getKey(), ['receiving_operator']);
foreach ([[$a, 20], [$b, 7]] as [$product, $quantity]) {
    Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => $quantity]);
}
for ($i = 0; $i < 30; $i++) {
    InventoryMovement::create(['product_id' => $a->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id, 'type' => 'in', 'quantity' => 1, 'reference' => 'BROWSER-'.$i]);
}

$pickingWarehouse = Warehouse::create(['warehouse_name' => 'Kitárolási próbaraktár', 'location' => 'Önálló böngészős tesztadat']);
foreach ([
    ['picking-supervisor@example.test', 'Felügyelő próbafelhasználó', 'supervisor'],
    ['picking-dispatch@example.test', 'Kitároló próbafelhasználó', 'dispatch_operator'],
] as [$email, $name, $role]) {
    $picker = User::factory()->create([
        'email' => $email, 'name' => $name, 'password' => Hash::make('browser-test-password'), 'role' => 'worker',
    ]);
    $picker->addWarehouseMembership($pickingWarehouse->getKey(), [$role]);
}
