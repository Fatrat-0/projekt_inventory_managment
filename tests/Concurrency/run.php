<?php

// Standalone MySQL integration runner. Never migrates or clears the application's database.
// Run: php tests/Concurrency/run.php
use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
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

if (($argv[1] ?? '') === '--worker') {
    connectTestDatabase($argv[2]);
    $job = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    echo "READY\n";
    flush();
    try {
        $service = app(InventoryService::class);
        match ($job['action']) {
            'movement' => $service->recordMovement($job['data']),
            'start' => $service->startTransfer($job['data']),
            'complete' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), true),
            'cancel' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), false),
        };
        echo "OK\n";
    } catch (ValidationException $e) {
        echo "REJECTED\n";
    } catch (Throwable $e) {
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

function race(string $database, int $productId, array $jobs): array
{
    $processes = [];
    DB::beginTransaction();
    try {
        Product::whereKey($productId)->lockForUpdate()->firstOrFail();
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
        // Both workers must still be waiting while the parent owns the product lock.
        usleep(250000);
        foreach ($processes as $process) {
            check($process->isRunning(), 'A worker bypassed the shared product lock.');
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
    $engines = DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?, ?, ?)', [$database, 'products', 'stocks', 'inventory_movements', 'inventory_transfers']);
    check(count($engines) === 4 && count(array_filter($engines, fn ($row) => $row->ENGINE === 'InnoDB')) === 4, 'Concurrency tests require InnoDB tables.');
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
    echo "8 MySQL concurrency scenarios passed.\n";
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
