<?php

// Standalone MySQL integration runner. Never migrates or clears the application's database.
// Run: php tests/Concurrency/run.php
use App\Models\{Category, InventoryMovement, InventoryTransfer, Partner, Product, ReturnQualityAction, Shipment, ShipmentStop, Stock, User, Warehouse};
use App\Services\{InventoryService, MasterDataEditor, ShipmentService};
use App\Queries\{DashboardOverview, ProductListing, ReturnQualityListing};
use App\Support\DashboardLayout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function connectTestDatabase(string $name): void
{
    if (!preg_match('/^inventory_race_test_[a-f0-9]{16}$/D', $name)) {
        throw new RuntimeException('Invalid isolated test database name.');
    }
    $config = config('database.connections.mysql');
    $config['database'] = $name;
    $config['url'] = null;
    config(['database.connections.inventory_race' => $config, 'database.default' => 'inventory_race']);
    DB::purge('inventory_race');
}

function saveDashboardLayout(array $job): void
{
    $request = Request::create('/dashboard/layout', 'PATCH', $job['data'], server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(fn () => User::findOrFail($job['id']));
    $response = app(\App\Http\Controllers\DashboardLayoutController::class)->update($request);
    if ($response->getStatusCode() === 409) throw ValidationException::withMessages(['version' => 'Stale dashboard layout.']);
    check($response->getStatusCode() === 200, 'Dashboard layout did not save.');
}

if (($argv[1] ?? '') === '--worker') {
    connectTestDatabase($argv[2]);
    $job = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    $outerSnapshot = $job['outerSnapshot'] ?? false;
    if ($outerSnapshot) {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        // Establish the snapshot before either worker can mutate stock/history.
        InventoryMovement::count();
    }
    echo "READY\n";
    flush();
    try {
        $service = app(InventoryService::class);
        match ($job['action']) {
            'movement' => $service->recordMovement($job['data'], $job['requestKey'] ?? null),
            'return' => $service->recordReturn(InventoryMovement::findOrFail($job['id']), $job['data'], $job['requestKey'] ?? null),
            'quality' => $service->processReturnQuality(InventoryMovement::findOrFail($job['id']), $job['data'], $job['requestKey'] ?? null),
            'user_delete' => User::findOrFail($job['id'])->delete(),
            'start' => $service->startTransfer($job['data'], $job['requestKey'] ?? null),
            'complete' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), true),
            'cancel' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), false),
            'shipment_start' => app(ShipmentService::class)->start(Shipment::findOrFail($job['id']), $job['actor'], 1),
            'shipment_receive' => app(ShipmentService::class)->receive(Shipment::findOrFail($job['id']), ShipmentStop::findOrFail($job['stop']), $job['actor']),
            'shipment_cancel' => app(ShipmentService::class)->cancel(Shipment::findOrFail($job['id'])),
            'master_edit' => MasterDataEditor::update(
                (new ($job['model']))->findOrFail($job['id']), Request::create('/', 'PUT', ['lock_version' => 1]), $job['data'], '/'),
            'partner_archive' => (new \App\Http\Controllers\PartnerController)->archive(Request::create('/', 'PATCH'), Partner::findOrFail($job['id'])),
            'dashboard_layout' => saveDashboardLayout($job),
        };
        if ($outerSnapshot) DB::commit();
        echo "OK\n";
    } catch (ValidationException $e) {
        if ($outerSnapshot && DB::transactionLevel() > 0) DB::rollBack();
        echo "REJECTED\n";
    } catch (Throwable $e) {
        if ($outerSnapshot && DB::transactionLevel() > 0) DB::rollBack();
        fwrite(STDERR, get_class($e).': '.$e->getMessage());
        exit(1);
    }
    exit(0);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function race(string $database, int|array $productId, array $jobs, ?int $userBarrier = null, ?\Illuminate\Database\Eloquent\Model $recordBarrier = null): array
{
    $processes = [];
    DB::beginTransaction();
    try {
        if ($recordBarrier !== null) {
            $recordBarrier->newQuery()->whereKey($recordBarrier->getKey())->lockForUpdate()->firstOrFail();
        } elseif ($userBarrier !== null) {
            User::whereKey($userBarrier)->lockForUpdate()->firstOrFail();
        } else {
            Product::whereIn('product_id', (array) $productId)->orderBy('product_id')->lockForUpdate()->get();
        }
        foreach ($jobs as $job) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $database, base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], dirname(__DIR__, 2));
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        do {
            $ready = count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'READY')));
            if ($ready === count($processes)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        check($ready === count($processes), 'Workers did not reach the start barrier.');
        // Both workers must still be waiting while the parent owns the selected barrier row.
        usleep(250000);
        foreach ($processes as $process) {
            check($process->isRunning(), 'A worker bypassed the selected start barrier.');
        }
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            check($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
            $lines = preg_split('/\R/', trim($process->getOutput()));
            $results[] = end($lines);
        }
        sort($results);
        return $results;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}

$database = 'inventory_race_test_'.bin2hex(random_bytes(8));
$admin = DB::connection('mysql');
$created = false;
$exitCode = 0;
try {
    $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    connectTestDatabase($database);
    check(Artisan::call('migrate', ['--database' => 'inventory_race', '--force' => true]) === 0, 'Test migrations failed.');
    $tables = ['users', 'products', 'partners', 'warehouses', 'categories', 'stocks', 'inventory_movements', 'inventory_transfers', 'inventory_movement_requests', 'inventory_transfer_requests', 'return_quality_actions', 'shipments', 'shipment_stops', 'shipment_items'];
    $engines = DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).')', [$database, ...$tables]);
    check(count($engines) === count($tables) && count(array_filter($engines, fn ($row) => $row->ENGINE === 'InnoDB')) === count($tables), 'Concurrency tests require InnoDB tables.');
    $user = User::factory()->create(['role' => 'worker']);
    $category = Category::create(['category_name' => 'Concurrency']);
    $service = app(InventoryService::class);
    $scenarios = ['double issue', 'double start', 'first receipts', 'double complete', 'complete versus cancel', 'two arrivals', 'issue versus start', 'double cancel'];
    foreach ($scenarios as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'RACE-'.$index, 'price' => 100]);
        $source = Warehouse::create(['warehouse_name' => 'Source '.$index, 'location' => 'Test']);
        $destination = Warehouse::create(['warehouse_name' => 'Destination '.$index, 'location' => 'Test']);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $source->warehouse_id, 'quantity' => 10]);
        $movement = ['product_id' => $product->product_id, 'warehouse_id' => $source->warehouse_id, 'type' => 'out', 'quantity' => 7, 'user_id' => $user->id];
        $transferData = ['product_id' => $product->product_id, 'from_warehouse_id' => $source->warehouse_id, 'to_warehouse_id' => $destination->warehouse_id, 'quantity' => 7, 'user_id' => $user->id];
        $transfer = null;
        if (in_array($scenario, ['double complete', 'complete versus cancel', 'two arrivals', 'double cancel'], true)) {
            $transfer = $service->startTransfer(array_replace($transferData, ['quantity' => 4]));
        }
        $jobs = match ($scenario) {
            'double issue' => [['action' => 'movement', 'data' => $movement], ['action' => 'movement', 'data' => $movement]],
            'double start' => [['action' => 'start', 'data' => $transferData], ['action' => 'start', 'data' => $transferData]],
            'first receipts' => [
                ['action' => 'movement', 'data' => array_replace($movement, ['type' => 'in', 'warehouse_id' => $destination->warehouse_id, 'quantity' => 5])],
                ['action' => 'movement', 'data' => array_replace($movement, ['type' => 'in', 'warehouse_id' => $destination->warehouse_id, 'quantity' => 7])],
            ],
            'double complete' => [['action' => 'complete', 'id' => $transfer->id], ['action' => 'complete', 'id' => $transfer->id]],
            'complete versus cancel' => [['action' => 'complete', 'id' => $transfer->id], ['action' => 'cancel', 'id' => $transfer->id]],
            'two arrivals' => [
                ['action' => 'complete', 'id' => $transfer->id],
                ['action' => 'complete', 'id' => $service->startTransfer(array_replace($transferData, ['quantity' => 3]))->id],
            ],
            'issue versus start' => [['action' => 'movement', 'data' => $movement], ['action' => 'start', 'data' => $transferData]],
            'double cancel' => [['action' => 'cancel', 'id' => $transfer->id], ['action' => 'cancel', 'id' => $transfer->id]],
        };
        $results = race($database, $product->product_id, $jobs);
        $expected = in_array($scenario, ['first receipts', 'two arrivals'], true) ? ['OK', 'OK'] : ['OK', 'REJECTED'];
        check($results === $expected, $scenario.': unexpected worker outcomes.');
        $sourceQty = (int) Stock::where('product_id', $product->product_id)->where('warehouse_id', $source->warehouse_id)->value('quantity');
        $destinationQty = (int) Stock::where('product_id', $product->product_id)->where('warehouse_id', $destination->warehouse_id)->value('quantity');
        $pending = (int) InventoryTransfer::where('product_id', $product->product_id)->where('status', 'pending')->sum('quantity');
        if (in_array($scenario, ['double issue', 'double start', 'issue versus start'], true)) {
            check($sourceQty === 3, $scenario.': incorrect source balance.');
            check(InventoryMovement::where('product_id', $product->product_id)->count() + InventoryTransfer::where('product_id', $product->product_id)->count() === 1, 'Rejected operation left a log.');
        } elseif ($scenario === 'first receipts') {
            check($sourceQty === 10 && $destinationQty === 12, 'Concurrent receipts lost stock.');
            check(Stock::where('product_id', $product->product_id)->count() === 2, 'Duplicate destination stock.');
            check(InventoryMovement::where('product_id', $product->product_id)->count() === 2, 'Receipt log missing.');
        } else {
            check($sourceQty + $destinationQty + $pending === 10 && $pending === 0, $scenario.': total stock not conserved.');
            if ($scenario === 'double complete') check($sourceQty === 6 && $destinationQty === 4, 'Duplicate arrival.');
            if ($scenario === 'double cancel') check($sourceQty === 10 && $destinationQty === 0, 'Duplicate cancellation.');
            if ($scenario === 'two arrivals') check($sourceQty === 3 && $destinationQty === 7, 'Concurrent arrivals lost stock.');
            if ($scenario === 'complete versus cancel') {
                check(in_array([$sourceQty, $destinationQty], [[6, 4], [10, 0]], true), 'Both finalizations took effect.');
            }
        }
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['same request replay', 'same key changed quantity', 'same key different products'] as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'DEDUP-'.$index, 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Dedup '.$index, 'location' => 'Test']);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 10]);
        $data = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id, 'type' => 'out', 'quantity' => 4];
        $key = (string) \Illuminate\Support\Str::uuid();
        $secondData = $data;
        $ids = [$product->product_id];
        if ($scenario === 'same key changed quantity') $secondData['quantity'] = 5;
        if ($scenario === 'same key different products') {
            $other = Product::create(['category_id' => $category->category_id, 'product_name' => 'Other', 'sku' => 'DEDUP-OTHER', 'price' => 100]);
            Stock::create(['product_id' => $other->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 10]);
            $secondData['product_id'] = $other->product_id;
            $ids[] = $other->product_id;
        }
        $beforeCount = InventoryMovement::count();
        $results = race($database, $ids, [
            ['action' => 'movement', 'data' => $data, 'requestKey' => $key],
            ['action' => 'movement', 'data' => $secondData, 'requestKey' => strtoupper($key)],
        ]);
        check($results === ($scenario === 'same request replay' ? ['OK', 'OK'] : ['OK', 'REJECTED']), $scenario.': incorrect request outcomes.');
        check(InventoryMovement::count() === $beforeCount + 1, $scenario.': duplicate movement.');
        $movement = InventoryMovement::latest('id')->firstOrFail();
        check($movement->stock_before === 10 && $movement->stock_after === 10 - $movement->quantity, 'Incorrect concurrent audit balances.');
        check((int) Stock::whereIn('product_id', $ids)->sum('quantity') === 10 * count($ids) - $movement->quantity, 'Request replay changed stock twice.');
        check(DB::table('inventory_movement_requests')->where('request_key', $key)->count() === 1, 'Duplicate request record.');
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['transfer request replay', 'transfer key changed quantity'] as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'TRANSFER-DEDUP-'.$index, 'price' => 100]);
        $source = Warehouse::create(['warehouse_name' => 'Transfer dedup source '.$index]);
        $target = Warehouse::create(['warehouse_name' => 'Transfer dedup target '.$index]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $source->warehouse_id, 'quantity' => 10]);
        $data = ['product_id' => $product->product_id, 'from_warehouse_id' => $source->warehouse_id, 'to_warehouse_id' => $target->warehouse_id, 'quantity' => 4, 'user_id' => $user->id];
        $key = (string)\Illuminate\Support\Str::uuid();
        $second = $scenario === 'transfer request replay' ? $data : array_replace($data, ['quantity' => 5]);
        $result = race($database, $product->product_id, [['action' => 'start', 'data' => $data, 'requestKey' => $key], ['action' => 'start', 'data' => $second, 'requestKey' => strtoupper($key)]]);
        check($result === ($scenario === 'transfer request replay' ? ['OK','OK'] : ['OK','REJECTED']), $scenario.': wrong outcomes.');
        $transfer = InventoryTransfer::where('product_id', $product->product_id)->sole();
        check((int)Stock::where('product_id', $product->product_id)->sum('quantity') + $transfer->quantity === 10, 'Transfer replay lost stock.');
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['double shipment start', 'double stop receipt', 'stop receipt versus cancellation', 'double shipment cancellation', 'two shipments shared stock', 'shipment versus issue'] as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'SHIPMENT-RACE-'.$index, 'price' => 100]);
        $source = Warehouse::create(['warehouse_name' => 'Shipment source '.$index]);
        $target = Warehouse::create(['warehouse_name' => 'Shipment target '.$index]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $source->warehouse_id, 'quantity' => 10]);
        $data = ['reference' => $scenario, 'origin_warehouse_id' => $source->warehouse_id, 'stops' => [['warehouse_id' => $target->warehouse_id, 'items' => [['product_id' => $product->product_id, 'quantity' => 7]]]]];
        $shipmentService = app(ShipmentService::class);
        $shipment = $shipmentService->save($data, $user->id);
        $stop = $shipment->stops()->firstOrFail();
        $job = ['id' => $shipment->id, 'stop' => $stop->id, 'actor' => $user->id];
        if (in_array($scenario, ['double stop receipt', 'stop receipt versus cancellation', 'double shipment cancellation'], true)) $shipmentService->start($shipment, $user->id, 1);
        $start = $job + ['action' => 'shipment_start']; $receive = $job + ['action' => 'shipment_receive']; $cancel = $job + ['action' => 'shipment_cancel'];
        $jobs = match ($scenario) {
            'double shipment start' => [$start, $start],
            'double stop receipt' => [$receive, $receive],
            'stop receipt versus cancellation' => [$receive, $cancel],
            'double shipment cancellation' => [$cancel, $cancel],
            'two shipments shared stock' => [$start, ['action' => 'shipment_start', 'id' => $shipmentService->save($data, $user->id)->id, 'actor' => $user->id]],
            'shipment versus issue' => [$start, ['action' => 'movement', 'data' => ['product_id' => $product->product_id, 'warehouse_id' => $source->warehouse_id, 'user_id' => $user->id, 'type' => 'out', 'quantity' => 7]]],
        };
        $result = race($database, $product->product_id, $jobs);
        $bothOk = in_array($scenario, ['double shipment start', 'double stop receipt', 'double shipment cancellation'], true);
        check($result === ($bothOk ? ['OK','OK'] : ['OK','REJECTED']), $scenario.': wrong outcomes.');
        $stock = (int)Stock::where('product_id', $product->product_id)->sum('quantity');
        $pending = (int)InventoryTransfer::where('product_id', $product->product_id)->where('status', 'pending')->sum('quantity');
        $issued = (int)InventoryMovement::where('product_id', $product->product_id)->sum('quantity');
        check($stock + $pending + $issued === 10, $scenario.': total stock not conserved.');
        check(InventoryTransfer::where('product_id', $product->product_id)->count() <= 1, $scenario.': duplicate transfers.');
        if ($scenario === 'double stop receipt') check((int)Stock::where('product_id', $product->product_id)->where('warehouse_id', $target->warehouse_id)->value('quantity') === 7, 'Stop received twice.');
        if ($scenario === 'double shipment cancellation') check($stock === 10 && $pending === 0, 'Shipment cancelled twice.');
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['return quota', 'return replay', 'return key changed quantity', 'supplier return versus issue',
        'return quota with outer snapshot', 'return replay with outer snapshot', 'return replay triple with outer snapshot'] as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'RETURN-RACE-'.$index, 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Return race '.$index, 'location' => 'Test']);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 10]);
        $base = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id];
        $supplierRace = $scenario === 'supplier return versus issue';
        $quotaRace = str_starts_with($scenario, 'return quota');
        $replayRace = str_starts_with($scenario, 'return replay');
        $outerSnapshot = str_ends_with($scenario, 'with outer snapshot');
        $original = $service->recordMovement($base + ['type' => $supplierRace ? 'in' : 'out', 'quantity' => $supplierRace ? 10 : 5]);
        if ($supplierRace) $service->recordMovement($base + ['type' => 'out', 'quantity' => 14]);
        $beforeStock = (int)Stock::where('product_id', $product->product_id)->value('quantity');
        $beforeMovementCount = InventoryMovement::where('product_id', $product->product_id)->count();
        $quantity = $supplierRace ? 4 : ($quotaRace ? 3 : 2);
        $data = ['user_id' => $user->id, 'quantity' => $quantity, 'return_reason' => 'Concurrent return', 'reference' => 'RETURN-RACE-'.$index];
        $key = (string)\Illuminate\Support\Str::uuid();
        $secondKey = $replayRace || $scenario === 'return key changed quantity' ? strtoupper($key) : (string)\Illuminate\Support\Str::uuid();
        $firstJob = ['action' => 'return', 'id' => $original->id, 'data' => $data, 'requestKey' => $key, 'outerSnapshot' => $outerSnapshot];
        $secondJob = $supplierRace
            ? ['action' => 'movement', 'data' => $base + ['type' => 'out', 'quantity' => 4], 'requestKey' => $secondKey]
            : array_replace($firstJob, ['requestKey' => $secondKey, 'data' => $scenario === 'return key changed quantity' ? array_replace($data, ['quantity' => 3]) : $data]);
        $jobs = [$firstJob, $secondJob];
        if ($scenario === 'return replay triple with outer snapshot') $jobs[] = $secondJob;
        $result = race($database, $product->product_id, $jobs);
        check($result === ($replayRace ? array_fill(0, count($jobs), 'OK') : ['OK', 'REJECTED']), $scenario.': wrong outcomes.');
        $returns = $original->returns()->get();
        check((int)$returns->sum('quantity') <= $original->quantity, $scenario.': cumulative return limit exceeded.');
        check(InventoryMovement::where('product_id', $product->product_id)->count() === $beforeMovementCount + 1, $scenario.': extra or missing movement.');
        $winner = InventoryMovement::where('product_id', $product->product_id)->latest('id')->firstOrFail();
        $afterStock = (int)Stock::where('product_id', $product->product_id)->value('quantity');
        check($winner->stock_before === $beforeStock && $winner->stock_after === $afterStock, $scenario.': incorrect audit balances.');
        check($afterStock === $beforeStock + ($winner->type === 'in' ? $winner->quantity : -$winner->quantity), $scenario.': stock applied incorrectly.');
        check(DB::table('inventory_movement_requests')->where('user_id', $user->id)->whereIn('request_key', array_unique([strtolower($key), strtolower($secondKey)]))->count() === 1,
            $scenario.': failed or replayed request left an extra claim.');
        check(DB::table('inventory_movement_requests')->whereNull('movement_id')->count() === 0, $scenario.': incomplete request claim.');
        if ($supplierRace) {
            check($afterStock === 2, 'Supplier return and issue both consumed the same stock.');
            check($returns->count() === ($winner->isReturn() ? 1 : 0), 'Supplier return history is inconsistent with the winning operation.');
        } else {
            check($returns->count() === 1 && $winner->isReturn(), $scenario.': return history missing or duplicated.');
            check($winner->return_of_id === $original->id && $winner->type === 'in', $scenario.': wrong original or direction.');
        }
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['quality quota', 'quality replay', 'quality key changed action', 'quality quota with outer snapshot',
        'quality replay triple with outer snapshot', 'quality scrap versus unavailable issue', 'quality release versus issue'] as $index => $scenario) {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario, 'sku' => 'QUALITY-RACE-'.$index, 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Quality '.$index]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 8]);
        $base = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id];
        $original = $service->recordMovement($base + ['type' => 'out', 'quantity' => 6]);
        $returned = $service->recordReturn($original, ['quantity' => 6, 'return_reason' => 'Quality inspection', 'user_id' => $user->id]);
        $stockBefore = Stock::where('product_id', $product->product_id)->sole();
        check((int)$stockBefore->quantity === 8 && (int)$stockBefore->quarantined_quantity === 6, 'New return was not quarantined.');
        $key = (string)\Illuminate\Support\Str::uuid();
        $replay = str_starts_with($scenario, 'quality replay');
        $outer = str_ends_with($scenario, 'with outer snapshot');
        $scrapRace = $scenario === 'quality scrap versus unavailable issue';
        $issueRace = $scenario === 'quality release versus issue';
        $data = ['user_id' => $user->id, 'action' => $scrapRace ? 'scrap' : 'release', 'quantity' => 4, 'reason' => 'Concurrent quality check'];
        $first = ['action' => 'quality', 'id' => $returned->id, 'data' => $data, 'requestKey' => $key, 'outerSnapshot' => $outer];
        $second = $first;
        $second['requestKey'] = $replay || $scenario === 'quality key changed action' ? strtoupper($key) : (string)\Illuminate\Support\Str::uuid();
        if (!$replay) $second['data']['action'] = 'scrap';
        if ($scrapRace || $issueRace) {
            $second = ['action' => 'movement', 'data' => $base + ['type' => 'out', 'quantity' => $scrapRace ? 3 : 5]];
        }
        $jobs = [$first, $second];
        if ($scenario === 'quality replay triple with outer snapshot') $jobs[] = $second;
        $results = race($database, $product->product_id, $jobs);
        $expected = $replay ? array_fill(0, count($jobs), 'OK') : ['OK', 'REJECTED'];
        check($issueRace ? in_array($results, [['OK', 'OK'], ['OK', 'REJECTED']], true) : $results === $expected,
            $scenario.': wrong outcomes.');
        $actions = ReturnQualityAction::where('return_movement_id', $returned->id)->get();
        check($actions->count() === 1 && (int)$actions->sum('quantity') === 4, $scenario.': duplicate or missing quality action.');
        $action = $actions->sole();
        $scrapped = $action->action === 'scrap' ? 4 : 0;
        $issued = (int)InventoryMovement::where('product_id', $product->product_id)->whereNull('return_of_id')->whereNull('scrap_of_id')->where('id', '!=', $original->id)->sum('quantity');
        $stockAfter = Stock::where('product_id', $product->product_id)->sole();
        check((int)$stockAfter->quarantined_quantity === 2, $scenario.': wrong quarantine balance.');
        check((int)$stockAfter->quantity === 8 - $scrapped - $issued, $scenario.': physical stock was applied twice.');
        check((int)$stockAfter->quantity >= (int)$stockAfter->quarantined_quantity, $scenario.': unavailable stock was consumed.');
        check((int)$action->quarantined_before === 6 && (int)$action->quarantined_after === 2, $scenario.': wrong quarantine audit.');
        check((int)$action->stock_before === 8 && (int)$action->stock_after === 8 - $scrapped, $scenario.': wrong physical audit.');
        $scrapMovements = InventoryMovement::where('scrap_of_id', $returned->id)->get();
        check($scrapMovements->count() === ($scrapped ? 1 : 0), $scenario.': scrap movement missing or duplicated.');
        if ($scrapped) check((int)$action->movement_id === $scrapMovements->sole()->id, $scenario.': scrap audit lost its movement.');
        if ($scrapRace) check($issued === 0, 'Issue consumed quarantined stock while a scrap action ran.');
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach (['conflicting quality actions by different users', 'compatible quality actions by different users',
        'quality release versus another customer return'] as $index => $scenario) {
        $secondUser = User::factory()->create(['role' => 'manager']);
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario,
            'sku' => 'QUALITY-MULTIUSER-'.$index, 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Multiuser '.$index]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 10]);
        $original = $service->recordMovement(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id,
            'user_id' => $user->id, 'type' => 'out', 'quantity' => 8]);
        $returned = $service->recordReturn($original, ['quantity' => 6, 'return_reason' => 'Initial customer return', 'user_id' => $user->id]);
        // Deliberately reuse the UUID across actors: request identity is per user,
        // whereas quantity limits apply to everyone sharing this return.
        $key = (string)\Illuminate\Support\Str::uuid();
        $first = ['action' => 'quality', 'id' => $returned->id, 'outerSnapshot' => true, 'requestKey' => $key,
            'data' => ['user_id' => $user->id, 'action' => 'release', 'quantity' => $index === 1 ? 2 : 4, 'reason' => 'Accept inspected goods']];
        $second = ['action' => 'quality', 'id' => $returned->id, 'outerSnapshot' => true, 'requestKey' => $key,
            'data' => ['user_id' => $secondUser->id, 'action' => 'scrap', 'quantity' => 4, 'reason' => 'Reject damaged goods']];
        if ($index === 2) {
            $second = ['action' => 'return', 'id' => $original->id, 'outerSnapshot' => true, 'requestKey' => $key,
                'data' => ['user_id' => $secondUser->id, 'quantity' => 2, 'return_reason' => 'Remaining goods returned']];
        }
        check(race($database, $product->product_id, [$first, $second]) === ($index === 0 ? ['OK', 'REJECTED'] : ['OK', 'OK']),
            $scenario.': unexpected outcomes.');
        $actions = ReturnQualityAction::where('return_movement_id', $returned->id)->get();
        $stock = Stock::where('product_id', $product->product_id)->sole();
        if ($index === 0) {
            check($actions->count() === 1 && (int)$actions->sum('quantity') === 4, $scenario.': quota exceeded.');
            $winner = $actions->sole();
            check((int)$winner->user_id === ($winner->action === 'release' ? $user->id : $secondUser->id), $scenario.': wrong actor.');
            check((int)$stock->quantity === ($winner->action === 'release' ? 8 : 4) && (int)$stock->quarantined_quantity === 2,
                $scenario.': stock or quarantine changed twice.');
        } elseif ($index === 1) {
            check($actions->count() === 2 && (int)$actions->sum('quantity') === 6, $scenario.': valid partial actions lost.');
            check((int)$actions->where('action', 'release')->sole()->user_id === $user->id
                && (int)$actions->where('action', 'scrap')->sole()->user_id === $secondUser->id, $scenario.': actors lost.');
            check((int)$stock->quantity === 4 && (int)$stock->quarantined_quantity === 0, $scenario.': wrong final balance.');
        } else {
            check($actions->count() === 1 && (int)$actions->sole()->user_id === $user->id && (int)$actions->sum('quantity') === 4,
                $scenario.': quality action lost.');
            $returns = $original->returns()->get();
            check($returns->count() === 2 && (int)$returns->sum('quantity') === 8
                && $returns->contains(fn ($item) => (int)$item->user_id === $secondUser->id && (int)$item->quantity === 2),
                $scenario.': new return lost or return limit exceeded.');
            check((int)$stock->quantity === 10 && (int)$stock->quarantined_quantity === 4, $scenario.': wrong shared balance.');
        }
        check(InventoryMovement::where('scrap_of_id', $returned->id)->count() === $actions->where('action', 'scrap')->count(),
            $scenario.': scrap audit is missing or duplicated.');
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    // Repeat the cross-product index-boundary race; which insert wins is nondeterministic.
    for ($collisionRun = 0; $collisionRun < 5; $collisionRun++) {
        $qualityProducts = [];
        $qualityReturns = [];
        $qualityWarehouse = Warehouse::create(['warehouse_name' => 'Quality cross-product request']);
        foreach ([1, 2] as $index) {
            $product = Product::create(['category_id' => $category->category_id, 'product_name' => 'Quality cross-product '.$index,
                'sku' => 'QUALITY-CROSS-'.$collisionRun.'-'.$index, 'price' => 100]);
            Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $qualityWarehouse->warehouse_id, 'quantity' => 8]);
            $original = $service->recordMovement(['product_id' => $product->product_id, 'warehouse_id' => $qualityWarehouse->warehouse_id,
                'user_id' => $user->id, 'type' => 'out', 'quantity' => 6]);
            $qualityProducts[] = $product->product_id;
            $qualityReturns[] = $service->recordReturn($original, ['quantity' => 6, 'return_reason' => 'Cross-product inspection', 'user_id' => $user->id]);
        }
        $key = (string)\Illuminate\Support\Str::uuid();
        $jobs = array_map(fn ($return, $index) => ['action' => 'quality', 'id' => $return->id,
            'data' => ['user_id' => $user->id, 'action' => 'release', 'quantity' => 4, 'reason' => 'Cross-product request collision'],
            'requestKey' => $index === 0 ? $key : strtoupper($key), 'outerSnapshot' => true], $qualityReturns, [0, 1]);
        check(race($database, $qualityProducts, $jobs) === ['OK', 'REJECTED'], 'Quality same key across products was not rejected.');
        $action = ReturnQualityAction::where('user_id', $user->id)->where('request_key', $key)->sole();
        check(in_array((int)$action->return_movement_id, array_map(fn ($return) => $return->id, $qualityReturns), true),
            'Cross-product request points to an unrelated return.');
        check((int)$action->quantity === 4 && $action->action === 'release', 'Cross-product action is incomplete.');
        check(ReturnQualityAction::whereIn('return_movement_id', array_map(fn ($return) => $return->id, $qualityReturns))->count() === 1,
            'Cross-product collision left a second action.');
        $stocks = Stock::whereIn('product_id', $qualityProducts)->get();
        check((int)$stocks->sum('quantity') === 16 && (int)$stocks->sum('quarantined_quantity') === 8,
            'Cross-product collision changed more than one quarantine balance.');
        check($stocks->pluck('quarantined_quantity')->sort()->values()->all() === [2, 6], 'Both products were released under one key.');
        check((int)$action->stock_before === 8 && (int)$action->stock_after === 8
            && (int)$action->quarantined_before === 6 && (int)$action->quarantined_after === 2,
            'Cross-product collision audit is inconsistent.');
        echo 'PASS: quality same key different products with outer snapshot ('.($collisionRun + 1).'/5)'.PHP_EOL;
    }
    foreach (['quality versus account deletion', 'quality versus account deletion with outer snapshot'] as $index => $scenario) {
        $actor = User::factory()->create(['role' => 'worker']);
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $scenario,
            'sku' => 'QUALITY-USER-'.$index, 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Quality account race '.$index]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 8]);
        // The actor being deleted has no other history: only this first inspection may protect it.
        $original = $service->recordMovement(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id,
            'user_id' => $user->id, 'type' => 'out', 'quantity' => 6]);
        $returned = $service->recordReturn($original, ['quantity' => 6, 'return_reason' => 'Account race inspection', 'user_id' => $user->id]);
        $outerSnapshot = str_ends_with($scenario, 'with outer snapshot');
        $jobs = [
            ['action' => 'quality', 'id' => $returned->id, 'requestKey' => (string)\Illuminate\Support\Str::uuid(),
                'data' => ['user_id' => $actor->id, 'action' => 'release', 'quantity' => 4, 'reason' => 'First quality action races account deletion'],
                'outerSnapshot' => $outerSnapshot],
            ['action' => 'user_delete', 'id' => $actor->id, 'outerSnapshot' => $outerSnapshot],
        ];
        check(race($database, $product->product_id, $jobs, $actor->id) === ['OK', 'REJECTED'], $scenario.': expected one safe rejection.');
        $actorExists = User::whereKey($actor->id)->exists();
        $actions = ReturnQualityAction::where('return_movement_id', $returned->id)->get();
        $stock = Stock::where('product_id', $product->product_id)->sole();
        check($actions->count() === ($actorExists ? 1 : 0), $scenario.': account/history mismatch.');
        check((int)$stock->quantity === 8 && (int)$stock->quarantined_quantity === ($actorExists ? 2 : 6),
            $scenario.': rejected operation changed stock.');
        check(InventoryMovement::where('product_id', $product->product_id)->count() === 2,
            $scenario.': account deletion removed the source movement or return.');
        if ($actorExists) {
            $action = $actions->sole();
            check((int)$action->user_id === $actor->id && (int)$action->quantity === 4,
                $scenario.': inspection actor or quantity was lost.');
            check((int)$action->stock_before === 8 && (int)$action->stock_after === 8
                && (int)$action->quarantined_before === 6 && (int)$action->quarantined_after === 2,
                $scenario.': inspection audit is inconsistent.');
        }
        echo 'PASS: '.$scenario.PHP_EOL;
    }
    foreach ([false, true] as $outerSnapshot) {
        $suffix = $outerSnapshot ? ' with outer snapshot' : '';
        foreach ([Product::class, Partner::class, Warehouse::class, Category::class] as $model) {
            [$data, $field] = match ($model) {
                Product::class => [['product_name' => 'Master edit product', 'sku' => 'MASTER-'.(int)$outerSnapshot, 'price' => 100, 'category_id' => $category->category_id], 'product_name'],
                Partner::class => [['name' => 'Master edit partner', 'type' => 'customer', 'phone' => '0'], 'name'],
                Warehouse::class => [['warehouse_name' => 'Master edit warehouse', 'location' => 'Address'], 'warehouse_name'],
                Category::class => [['category_name' => 'Master edit category', 'default_item_min_stock' => 0], 'category_name'],
            };
            $record = $model::create($data)->fresh();
            $jobs = array_map(fn ($name) => ['action' => 'master_edit', 'model' => $model, 'id' => $record->getKey(),
                'data' => array_replace($data, [$field => $name]), 'outerSnapshot' => $outerSnapshot], ['First editor', 'Second editor']);
            $scenario = class_basename($model).' conflicting edits'.$suffix;
            check(race($database, [], $jobs, recordBarrier: $record) === ['OK', 'REJECTED'], $scenario.': expected one conflict.');
            $saved = $record->fresh();
            check((int)$saved->lock_version === 2 && in_array($saved->getAttribute($field), ['First editor', 'Second editor'], true),
                $scenario.': wrong version or lost winner.');
            foreach (array_diff_key($data, [$field => true]) as $key => $value) check($saved->getAttribute($key) == $value, $scenario.': unrelated field changed.');
            echo 'PASS: '.$scenario.PHP_EOL;
        }
        $record = Partner::create(['name' => 'Archive versus edit', 'type' => 'customer'])->fresh();
        $jobs = [
            ['action' => 'master_edit', 'model' => Partner::class, 'id' => $record->id, 'data' => ['name' => 'Edited before archive'], 'outerSnapshot' => $outerSnapshot],
            ['action' => 'partner_archive', 'id' => $record->id, 'outerSnapshot' => $outerSnapshot],
        ];
        $result = race($database, [], $jobs, recordBarrier: $record);
        check(in_array($result, [['OK', 'OK'], ['OK', 'REJECTED']], true), 'Archive/edit returned an unexpected error.');
        $saved = $record->fresh();
        check($saved->isArchived() && (int)$saved->lock_version === ($result === ['OK', 'OK'] ? 3 : 2)
            && $saved->name === ($result === ['OK', 'OK'] ? 'Edited before archive' : 'Archive versus edit'), 'Archive/edit lost an update.');
        echo 'PASS: partner archive versus edit'.$suffix.PHP_EOL;
    }
    $skuProducts = [];
    foreach ([1, 2] as $index) {
        $skuProducts[] = Product::create(['category_id' => $category->category_id, 'product_name' => 'SKU edit '.$index,
            'sku' => 'SKU-EDIT-'.$index, 'price' => 100])->fresh();
    }
    $jobs = array_map(fn ($product) => ['action' => 'master_edit', 'model' => Product::class, 'id' => $product->getKey(),
        'data' => ['sku' => 'SKU-EDIT-SHARED']], $skuProducts);
    check(race($database, array_map(fn ($product) => $product->getKey(), $skuProducts), $jobs) === ['OK', 'REJECTED'],
        'Concurrent SKU collision must be a validation error.');
    check(Product::where('sku', 'SKU-EDIT-SHARED')->count() === 1
        && array_sum(array_map(fn ($product) => (int)$product->fresh()->lock_version, $skuProducts)) === 3,
        'Concurrent SKU collision changed a rejected product version.');
    echo 'PASS: different products competing for the same SKU'.PHP_EOL;
    // Validate the read-only queue SQL on real MySQL using the completed race fixtures.
    // Its expected quantities are computed in PHP from individual immutable actions.
    $queueSnapshot = function (): string {
        $data = [];
        foreach (['stocks', 'inventory_movements', 'return_quality_actions'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    };
    $beforeQueueReads = $queueSnapshot();
    $queueExpected = [];
    foreach (InventoryMovement::with(['qualityActions', 'product', 'warehouse'])->whereNotNull('return_of_id')
        ->where('type', 'in')->where('quarantined_quantity', '>', 0)->get() as $return) {
        $released = (int)$return->qualityActions->where('action', 'release')->sum('quantity');
        $scrapped = (int)$return->qualityActions->where('action', 'scrap')->sum('quantity');
        $processed = (int)$return->qualityActions->sum('quantity');
        $remaining = max(0, (int)$return->quarantined_quantity - $processed);
        $queueExpected[(int)$return->id] = ['id' => (int)$return->id, 'original_quantity' => (int)$return->quarantined_quantity,
            'released_quantity' => $released, 'scrapped_quantity' => $scrapped, 'remaining_quantity' => $remaining,
            'status' => $remaining === 0 ? 'closed' : ($processed === 0 ? 'waiting' : 'partial'),
            'created_at' => $return->created_at->format('Y-m-d H:i:s'), 'product_name' => $return->product->product_name,
            'warehouse_name' => $return->warehouse->warehouse_name];
    }
    check(count($queueExpected) > 0, 'Queue SQL check requires eligible return fixtures.');
    $queueIds = array_keys($queueExpected);
    sort($queueIds);
    $summaryExpected = ['open_count' => 0, 'waiting_count' => 0, 'partial_count' => 0, 'closed_count' => 0, 'pending_quantity' => 0];
    $pendingDates = [];
    foreach ($queueExpected as $expected) {
        $summaryExpected[$expected['status'].'_count']++;
        if ($expected['remaining_quantity'] > 0) {
            $summaryExpected['open_count']++;
            $summaryExpected['pending_quantity'] += $expected['remaining_quantity'];
            $pendingDates[] = $expected['created_at'];
        }
    }
    $oldestExpected = $pendingDates === [] ? null : min($pendingDates);
    foreach (['all', 'open', 'waiting', 'partial', 'closed'] as $status) {
        $listing = new ReturnQualityListing(Request::create('/quality-returns', 'GET', ['status' => $status, 'per_page' => 100]));
        $rows = $listing->query()->get();
        $wanted = array_keys(array_filter($queueExpected, fn($row) => $status === 'all'
            || ($status === 'open' ? $row['remaining_quantity'] > 0 : $row['status'] === $status)));
        $actual = array_map('intval', $rows->pluck('id')->all());
        sort($wanted);
        sort($actual);
        check($actual === $wanted, 'Queue '.$status.': wrong MySQL status result set.');
        foreach ($rows as $row) {
            $expected = $queueExpected[(int)$row->id];
            foreach (['released_quantity', 'scrapped_quantity', 'remaining_quantity'] as $field) {
                check((int)$row->$field === $expected[$field], 'Queue '.$status.': incorrect '.$field.' aggregate.');
            }
            check(ReturnQualityListing::status($row) === $expected['status'], 'Queue '.$status.': incorrect derived state.');
        }
        $summary = $listing->summary();
        foreach ($summaryExpected as $field => $value) check($summary[$field] === $value, 'Queue '.$status.': wrong summary '.$field.'.');
        check($summary['oldest_at']?->format('Y-m-d H:i:s') === $oldestExpected, 'Queue '.$status.': wrong oldest pending date.');
    }
    $pendingSummary = ReturnQualityListing::pendingSummary();
    check($pendingSummary['returns_count'] === $summaryExpected['open_count']
        && $pendingSummary['quantity'] === $summaryExpected['pending_quantity']
        && $pendingSummary['oldest_at']?->format('Y-m-d H:i:s') === $oldestExpected, 'Queue dashboard summary differs from pending returns.');
    foreach (array_keys(ReturnQualityListing::SORTS) as $sort) {
        foreach (['asc', 'desc'] as $direction) {
            $listing = new ReturnQualityListing(Request::create('/quality-returns', 'GET', ['status' => 'all', 'per_page' => 100,
                'sort_by' => $sort, 'sort_dir' => $direction]));
            $actual = array_map('intval', $listing->query()->get()->pluck('id')->all());
            $actualSet = $actual;
            sort($actualSet);
            check($actualSet === $queueIds, 'Queue '.$sort.' '.$direction.': sorting lost or duplicated rows.');
            if (!in_array($sort, ['product_name', 'warehouse_name'], true)) {
                $expectedRows = array_values($queueExpected);
                usort($expectedRows, function ($left, $right) use ($sort, $direction) {
                    $compared = ($left[$sort] <=> $right[$sort]) ?: ($left['id'] <=> $right['id']);
                    return $direction === 'asc' ? $compared : -$compared;
                });
                check($actual === array_column($expectedRows, 'id'), 'Queue '.$sort.' '.$direction.': incorrect numeric/date order or tie breaker.');
            } else {
                // MySQL collation controls lexical ordering; exact equal names must still use stable IDs.
                for ($index = 1; $index < count($actual); $index++) {
                    if ($queueExpected[$actual[$index - 1]][$sort] !== $queueExpected[$actual[$index]][$sort]) continue;
                    check($direction === 'asc' ? $actual[$index - 1] < $actual[$index] : $actual[$index - 1] > $actual[$index],
                        'Queue '.$sort.' '.$direction.': unstable IDs for equal names.');
                }
            }
        }
    }
    check(hash_equals($beforeQueueReads, $queueSnapshot()), 'Queue reads changed stock, movement history or quality actions.');
    echo 'PASS: quality queue MySQL aggregates and filters'.PHP_EOL;
    $dashboardCategory = Category::create(['category_name' => 'Dashboard direct category', 'default_item_min_stock' => 5, 'aggregate_min_stock' => 7]);
    $dashboardChild = Category::create(['category_name' => 'Dashboard child', 'parent_id' => $dashboardCategory->category_id, 'aggregate_min_stock' => 50]);
    $dashboardWarehouse = Warehouse::create(['warehouse_name' => 'Dashboard MySQL']);
    foreach ([[$dashboardCategory, 10, 8, null], [$dashboardCategory, 0, 0, 0], [$dashboardChild, 100, 0, 0]] as $index => [$cat, $physical, $quarantine, $override]) {
        $product = Product::create(['product_name' => 'Dashboard MySQL '.$index, 'sku' => 'DASH-MYSQL-'.$index, 'price' => 100,
            'category_id' => $cat->category_id, 'min_stock_override' => $override]);
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $dashboardWarehouse->warehouse_id, 'quantity' => $physical, 'quarantined_quantity' => $quarantine]);
    }
    Category::create(['category_name' => 'Dashboard empty', 'aggregate_min_stock' => 3]);
    $expectedProductAlerts = Product::with(['category', 'stocks'])->get()->map(function ($product) {
        $minimum = $product->min_stock_override ?? $product->category?->default_item_min_stock;
        return ['id' => $product->product_id, 'shortage' => $minimum === null ? 0 : $minimum - $product->stocks->sum('available_quantity')];
    })->filter(fn ($row) => $row['shortage'] > 0)->sort(fn ($a, $b) => $b['shortage'] <=> $a['shortage'] ?: $a['id'] <=> $b['id'])->values();
    $expectedCategoryAlerts = Category::with('products.stocks')->get()->map(function ($category) {
        return ['id' => $category->category_id, 'shortage' => $category->aggregate_min_stock === null ? 0
            : $category->aggregate_min_stock - $category->products->sum(fn ($product) => $product->stocks->sum('available_quantity'))];
    })->filter(fn ($row) => $row['shortage'] > 0)->sort(fn ($a, $b) => $b['shortage'] <=> $a['shortage'] ?: $a['id'] <=> $b['id'])->values();
    $beforeDashboardReads = $queueSnapshot();
    foreach ([7, 30] as $days) {
        $overview = (new DashboardOverview)->data($days);
        check($overview['lowStockProductCount'] === $expectedProductAlerts->count()
            && $overview['lowStockProducts']->pluck('product_id')->all() === $expectedProductAlerts->take(DashboardOverview::LIST_PAGE_SIZE)->pluck('id')->all(), 'Dashboard MySQL product alerts are incorrect.');
        check($overview['lowStockCategoryCount'] === $expectedCategoryAlerts->count()
            && $overview['lowStockCategories']->pluck('category_id')->all() === $expectedCategoryAlerts->take(DashboardOverview::LIST_PAGE_SIZE)->pluck('id')->all(), 'Dashboard MySQL category alerts are incorrect.');
        check($overview['lowStockProductCount'] === (new ProductListing(Request::create('/products', 'GET', ['stock_status' => 'low'])))->query()->count(),
            'Dashboard/product list minimum semantics diverged.');
        check($overview['stockSummary']['physical'] === (int)Stock::sum('quantity')
            && $overview['stockSummary']['quarantined'] === (int)Stock::sum('quarantined_quantity')
            && $overview['stockSummary']['available'] === $overview['stockSummary']['physical'] - $overview['stockSummary']['quarantined'], 'Dashboard stock balance is inconsistent.');
        check($overview['transitSummary']['quantity'] === (int)InventoryTransfer::where('status', 'pending')->sum('quantity'), 'Dashboard duplicated pending shipment quantities.');
        $pendingIds = InventoryTransfer::where('status', 'pending')->orderBy('created_at')->orderBy('id')->pluck('id');
        check($overview['pendingTransfers']->total() === $pendingIds->count()
            && $overview['pendingTransfers']->pluck('id')->all() === $pendingIds->take(DashboardOverview::LIST_PAGE_SIZE)->all(), 'Dashboard pending transfer paging is incorrect.');
        $recentIds = InventoryMovement::where('created_at', '<=', $overview['asOf'])->orderByDesc('created_at')->orderByDesc('id')->pluck('id');
        check($overview['recentMovements']->total() === $recentIds->count()
            && $overview['recentMovements']->pluck('id')->all() === $recentIds->take(DashboardOverview::LIST_PAGE_SIZE)->all(), 'Dashboard movement paging is incorrect.');
        check($overview['dailyActivity']->count() === $days, 'Dashboard MySQL daily buckets are missing.');
        $movements = InventoryMovement::where('created_at', '>=', $overview['periodStart'])->where('created_at', '<=', $overview['asOf'])->get();
        check($overview['activitySummary']['count'] === $movements->count()
            && $overview['activitySummary']['in_quantity'] === (int)$movements->where('type', 'in')->sum('quantity')
            && $overview['activitySummary']['out_quantity'] === (int)$movements->where('type', 'out')->sum('quantity'), 'Dashboard MySQL activity totals are incorrect.');
    }
    check(hash_equals($beforeDashboardReads, $queueSnapshot()), 'Dashboard reads changed stock or history.');
    echo 'PASS: dashboard MySQL totals, alerts and daily activity'.PHP_EOL;
    foreach ([false, true] as $outerSnapshot) {
        $layoutUser = User::factory()->create(['role' => 'worker']);
        $beforePreferences = $queueSnapshot();
        $profile = $layoutUser->only(['name', 'email', 'password', 'role']);
        $a = DashboardLayout::defaults(); $a['visible'] = ['activity'];
        $b = ['order' => array_reverse(array_keys(DashboardLayout::PANELS)), 'visible' => ['tasks']];
        $jobs = array_map(fn ($layout) => ['action' => 'dashboard_layout', 'id' => $layoutUser->id, 'outerSnapshot' => $outerSnapshot, 'data' => $layout + ['version' => 0]], [$a, $b]);
        check(race($database, [], $jobs, userBarrier: $layoutUser->id) === ['OK', 'REJECTED'], 'Concurrent layouts both overwrote the same version.');
        $layoutUser->refresh();
        check($layoutUser->dashboard_layout_version === 1 && in_array($layoutUser->dashboard_layout, [$a, $b], true), 'Dashboard layout is mixed or its version advanced twice.');
        check($profile === $layoutUser->only(array_keys($profile)), 'Layout update changed profile or role.');
        check(hash_equals($beforePreferences, $queueSnapshot()), 'Layout preference changed inventory or history.');
        echo 'PASS: concurrent dashboard preferences'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }
    echo "56 MySQL concurrency checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    DB::disconnect('inventory_race');
    // Only the exact randomly generated database created by this run may be dropped.
    if ($created && preg_match('/^inventory_race_test_[a-f0-9]{16}$/D', $database)) {
        $admin->statement("DROP DATABASE `{$database}`");
        echo "Isolated test database removed.\n";
    }
}
exit($exitCode);
