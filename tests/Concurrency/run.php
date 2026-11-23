<?php

// Standalone MySQL integration runner. Never migrates or clears the application's database.
// Run: php tests/Concurrency/run.php
use App\Models\{Category, InventoryMovement, InventoryTransfer, Partner, Product, ReturnQualityAction, Shipment, ShipmentCargoConfirmation, ShipmentDispatch, ShipmentIntentAssignment, ShipmentPlanOperation, ShipmentReceiptItem, ShipmentStop, ShipmentTransitItem, Stock, TransferIntent, TransferWorkflowEvent, User, Vehicle, Warehouse, WarehouseLoadVerification, WarehouseLocation};
use App\Services\{InventoryService, MasterDataEditor, ShipmentCargoPreparation, ShipmentDispatchBlocked, ShipmentDispatchService, ShipmentPlanReadiness, ShipmentPlanningService, ShipmentReceiptPutawayService, ShipmentReceiving, ShipmentService, TransferIntentService, TransferStockReservationService, VehicleRegistry, WarehouseCapacity, WarehouseFloorPlan, WarehouseLoadVerificationService, WarehouseRelocationSources, WarehouseStockAvailability, WarehouseStorageDesigner, WarehouseTransferTaskService};
use App\Queries\{DashboardOverview, ProductListing, ReturnQualityListing};
use App\Support\DashboardLayout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
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

function runIntentOperation(array $job): void
{
    $service = app(TransferIntentService::class);
    $actor = User::findOrFail($job['actor']);
    $result = $job['operation'] === 'create'
        ? $service->create($actor, $job['data'])
        : $service->{$job['operation']}($actor, TransferIntent::findOrFail($job['id']), $job['data']);
    $status = ['create' => 'pending_approval', 'approve' => 'approved', 'reject' => 'rejected'][$job['operation']];
    check($result['intent']['status'] === $status && $result['intent']['version'] === ($job['operation'] === 'create' ? 1 : 2),
        'The intent operation did not return the winning transition snapshot.');
}

function runReservationOperation(array $job): void
{
    $intent = TransferIntent::findOrFail($job['id']);
    $actor = User::findOrFail($job['actor']);
    if ($job['operation'] === 'cancel') {
        app(TransferIntentService::class)->cancel($actor, $intent, $job['data']);
        return;
    }

    $result = app(TransferStockReservationService::class)->{$job['operation']}($actor, $intent, $job['data']);
    $status = $job['operation'] === 'reserve' ? 'active' : 'released';
    check($result['reservation']['status'] === $status, 'Wrong reservation snapshot for the winning operation.');
}

function runPlanOperation(array $job): void
{
    $service = app(ShipmentPlanningService::class);
    $actor = User::findOrFail($job['actor']);
    $shipment = Shipment::findOrFail($job['ship']);
    if ($job['operation'] === 'detach') {
        $service->detach($actor, $shipment, TransferIntent::findOrFail($job['intent']), $job['data']);
        return;
    }

    $result = match ($job['operation']) {
        'attach' => $service->attach($actor, $shipment, $job['data']),
        'reorder' => $service->reorder($actor, $shipment, $job['data']),
        'select_vehicle' => $service->selectVehicle($actor, $shipment, $job['data']),
        'save_cargo_item' => $service->saveCargoItem($actor, $shipment, $job['assignment'], $job['data']),
        'confirm_cargo' => $service->confirmCargo($actor, $shipment, $job['data']),
        'dispatch' => $service->dispatch($actor, $shipment, $job['data']),
    };
    check($result['shipment']['id'] === $shipment->id && $result['shipment']['version'] === $job['data']['version'] + 1,
        'The plan command did not return the committed version.');
    if ($job['operation'] === 'dispatch') {
        check($result['shipment']['status'] === 'in_transit' && $result['dispatch']['shipment_id'] === $shipment->id
            && $result['dispatch']['id'] > 0 && array_sum(array_column($result['transit_items'], 'dispatched_quantity')) === $job['expected_quantity'],
            'The dispatch command did not return the original shipment and transit snapshot.');
    }
}

function runReceiptOperation(array $job): array
{
    $result = app(ShipmentReceiving::class)->receive(
        User::findOrFail($job['actor']), Shipment::findOrFail($job['ship']), ShipmentStop::findOrFail($job['stop']), $job['data'],
    );
    $stored = DB::table('shipment_receipts')->where('operation_key', $job['data']['operation_key'])->sharedLock()->sole();
    check($result === json_decode($stored->result, true, flags: JSON_THROW_ON_ERROR)
        && $result['stop']['version'] === $job['data']['version'] + 1
        && $result['receipt']['id'] === (int) $stored->id,
        'The receipt did not return its original committed snapshot.');

    return $result;
}

function runPutawayOperation(array $job): array
{
    $result = app(ShipmentReceiptPutawayService::class)->putaway(
        User::findOrFail($job['actor']), ShipmentReceiptItem::findOrFail($job['id']), $job['data'],
    );
    $stored = DB::table('shipment_receipt_putaways')->where('operation_key', $job['data']['operation_key'])->sharedLock()->sole();
    check(array_diff_key($result, ['event' => true]) === json_decode($stored->result, true, flags: JSON_THROW_ON_ERROR)
        && $result['event']['id'] === (int) $stored->id
        && $result['event']['actor_id'] === $job['actor']
        && $result['item']['version'] === $job['data']['version'] + 1
        && (int) $stored->shipment_receipt_item_id === $job['id']
        && (int) $stored->quantity === $job['data']['quantity'],
        'Putaway did not return the immutable snapshot of the original operation.');

    return $result;
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
            'verify_load' => app(WarehouseLoadVerificationService::class)->confirm(
                User::findOrFail($job['actor']), Warehouse::findOrFail($job['warehouse']), $job['id'], $job['data'],
            ),
            'movement' => $service->recordMovement($job['data'], $job['requestKey'] ?? null),
            'manual_receipt' => $service->receiveManual($job['data'], $job['requestKey']),
            'relocate' => app(\App\Services\WarehouseRelocationService::class)->relocate(
                User::findOrFail($job['actor']), $job['data'],
            ),
            'manual_putaway' => app(\App\Services\ManualReceiptPutawayService::class)->putaway(
                User::findOrFail($job['actor']), \App\Models\ManualReceiptBatch::findOrFail($job['id']), $job['data'],
            ),
            'return' => $service->recordReturn(InventoryMovement::findOrFail($job['id']), $job['data'], $job['requestKey'] ?? null),
            'quality' => $service->processReturnQuality(InventoryMovement::findOrFail($job['id']), $job['data'], $job['requestKey'] ?? null),
            'user_delete' => User::findOrFail($job['id'])->delete(),
            'start' => $service->startTransfer($job['data'], $job['requestKey'] ?? null),
            'place' => app(WarehouseCapacity::class)->place(WarehouseLocation::findOrFail($job['id']), $job['data']),
            'floor_plan' => app(WarehouseFloorPlan::class)->save(Warehouse::findOrFail($job['id']), $job['data']),
            'storage_designer' => app(WarehouseStorageDesigner::class)->create(Warehouse::findOrFail($job['id']), $job['data']),
            'bin_configuration' => app(WarehouseStorageDesigner::class)->updateBin(Warehouse::findOrFail($job['id']), $job['system'], $job['bin'], $job['data']),
            'complete' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), true),
            'cancel' => $service->finalizeTransfer(InventoryTransfer::findOrFail($job['id']), false),
            'shipment_start' => app(ShipmentService::class)->start(Shipment::findOrFail($job['id']), $job['actor'], 1),
            'shipment_receive' => app(ShipmentService::class)->receive(Shipment::findOrFail($job['id']), ShipmentStop::findOrFail($job['stop']), $job['actor']),
            'shipment_cancel' => app(ShipmentService::class)->cancel(Shipment::findOrFail($job['id'])),
            'transfer_intent' => runIntentOperation($job),
            'stock_reservation' => runReservationOperation($job),
            'shipment_plan' => runPlanOperation($job),
            'shipment_2_receipt' => runReceiptOperation($job),
            'shipment_receipt_putaway' => runPutawayOperation($job),
            'vehicle_update' => app(VehicleRegistry::class)->save(User::findOrFail($job['actor']), $job['data'], Vehicle::findOrFail($job['id'])),
            'pick_task' => app(WarehouseTransferTaskService::class)->pick(User::findOrFail($job['actor']), TransferIntent::findOrFail($job['id']), $job['data']),
            'master_edit' => MasterDataEditor::update(
                (new ($job['model']))->findOrFail($job['id']), Request::create('/', 'PUT', ['lock_version' => 1]), $job['data'], '/'),
            'partner_archive' => (new \App\Http\Controllers\PartnerController)->archive(Request::create('/', 'PATCH'), Partner::findOrFail($job['id'])),
            'dashboard_layout' => saveDashboardLayout($job),
            'stock_minimum' => app(\App\Http\Controllers\StockController::class)->minimum(Request::create('/', 'PATCH', $job['data']), Stock::findOrFail($job['id'])),
        };
        if ($outerSnapshot) DB::commit();
        echo "OK\n";
    } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
        if ($outerSnapshot && DB::transactionLevel() > 0) DB::rollBack();
        if (isset($job['expected_blocker'])) {
            check($e instanceof ShipmentDispatchBlocked && in_array($job['expected_blocker'], array_column($e->blockers, 'code'), true),
                'The dispatch was not blocked by the expected prerequisite.');
        }
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

function race(
    string $database, int|array $productId, array $jobs, ?int $userBarrier = null,
    ?\Illuminate\Database\Eloquent\Model $recordBarrier = null, ?Closure $beforeRelease = null,
): array
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
        if ($beforeRelease !== null) $beforeRelease();
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
    $tables = ['users', 'products', 'partners', 'warehouses', 'categories', 'stocks', 'inventory_movements', 'inventory_transfers', 'inventory_movement_requests', 'inventory_transfer_requests', 'return_quality_actions', 'shipments', 'shipment_stops', 'shipment_items', 'warehouse_locations', 'warehouse_placements', 'inventory_transfer_source_allocations', 'transfer_intents', 'transfer_workflow_events', 'warehouse_memberships', 'transfer_stock_reservations', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations', 'vehicles', 'vehicle_operations', 'shipment_assignment_cargos', 'shipment_cargo_confirmations', 'shipment_plan_operations', 'shipment_intent_assignments', 'shipment_dispatches', 'shipment_transit_items', 'shipment_receipts', 'shipment_receipt_items', 'shipment_receipt_putaways'];
    $tables[] = 'inventory_movement_sources';
    array_push($tables, 'manual_receipt_batches', 'manual_receipt_putaways', 'warehouse_relocations');
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
    foreach ([false, true] as $outerSnapshot) {
        foreach (['target', 'source'] as $constraint) {
            $product = Product::create(['product_name' => 'Replenishment race', 'sku' => 'REPL-'.Str::random(12), 'price' => 1, 'category_id' => $dashboardCategory->category_id]);
            $origin = Warehouse::create(['warehouse_name' => 'Replenishment origin']);
            $destination = Warehouse::create(['warehouse_name' => 'Replenishment destination']);
            $source = Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $origin->warehouse_id, 'quantity' => $constraint === 'source' ? 12 : 20, 'min_quantity' => 5]);
            $target = Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $destination->warehouse_id, 'quantity' => 0, 'min_quantity' => 7]);
            $other = $constraint === 'source' ? Stock::create(['product_id' => $product->product_id,
                'warehouse_id' => Warehouse::create(['warehouse_name' => 'Other destination'])->warehouse_id, 'quantity' => 0, 'min_quantity' => 7]) : $target;
            $proposal = \App\Queries\ReplenishmentSuggestions::query()->where('needs.id', $target->id)->sole();
            check((int)$proposal->shortage === 7 && (int)$proposal->source_stock_id === $source->id, 'MySQL replenishment proposal is incorrect.');
            $jobs = array_map(fn ($row) => ['action' => 'start', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string)\Illuminate\Support\Str::uuid(),
                'data' => ['product_id' => $product->product_id, 'from_warehouse_id' => $origin->warehouse_id, 'to_warehouse_id' => $row->warehouse_id,
                    'quantity' => 7, 'user_id' => $layoutUser->id, 'replenishment_stock_id' => $row->id]], [$target, $other]);
            check(race($database, $product->product_id, $jobs) === ['OK', 'REJECTED'], 'Concurrent replenishment exceeded '.$constraint.' allowance.');
            check($source->fresh()->quantity === ($constraint === 'source' ? 5 : 13), 'Replenishment stock deducted incorrectly.');
            check(InventoryTransfer::where('product_id', $product->product_id)->count() === 1, 'Replenishment created duplicate transfers.');
            check($target->fresh()->quantity === 0 && $other->fresh()->quantity === 0, 'Pending replenishment changed destination stock.');
            echo 'PASS: replenishment '.$constraint.' guard'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
        $jobs = array_map(fn ($minimum) => ['action' => 'stock_minimum', 'id' => $source->id, 'outerSnapshot' => $outerSnapshot,
            'data' => ['min_quantity' => $minimum, 'expected_minimum' => 5]], [6, 7]);
        check(race($database, $product->product_id, $jobs) === ['OK', 'REJECTED'], 'Concurrent local minimum changes overwrote each other.');
        check(in_array($source->fresh()->min_quantity, [6, 7], true) && $source->fresh()->quantity === 5, 'Minimum edit changed stock.');
        echo 'PASS: concurrent local minimum changes'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }
    $literalProduct = Product::create(['product_name' => 'Stock MySQL literal', 'sku' => 'STOCK-%_!-LITERAL', 'price' => 1, 'category_id' => $dashboardChild->category_id]);
    $literalStock = Stock::create(['product_id' => $literalProduct->product_id, 'warehouse_id' => $dashboardWarehouse->warehouse_id, 'quantity' => 3, 'min_quantity' => 0]);
    $beforeStockReads = $queueSnapshot();
    $allStocks = Stock::with(['product.category', 'warehouse'])->get();
    $stockAvailability = app(WarehouseStockAvailability::class);
    $stockStates = $allStocks->mapWithKeys(fn ($row) => [$row->id => $stockAvailability->snapshot($row->warehouse_id, $row->product_id)]);
    foreach (array_keys(\App\Queries\StockListing::STATUSES) as $status) {
        $wanted = $allStocks->filter(function ($row) use ($stockStates, $status) {
            $direct = $stockStates[$row->id]['direct_issue_quantity'];
            $pickable = $stockStates[$row->id]['pickable_quantity'];
            return match ($status) {
                'low' => $pickable !== null && $pickable < $row->min_quantity,
                'out' => $direct === 0,
                'positive' => $direct > 0,
                'quarantined' => $row->quarantined_quantity > 0,
                'zero' => $row->quantity === 0,
                'ok' => $pickable >= $row->min_quantity && $pickable > 0,
                'unknown' => $direct === null,
                default => true,
            };
        });
        $listing = new \App\Queries\StockListing(Request::create('/stock', 'GET', ['stock_status' => $status]));
        $actualIds = $listing->query()->pluck('stocks.id')->all(); $expectedIds = $wanted->pluck('id')->all();
        sort($actualIds); sort($expectedIds);
        check($actualIds === $expectedIds, 'Stock listing MySQL '.$status.' returned incorrect rows.');
        $summary = $listing->summary();
        check((int)$summary->physical === (int)$wanted->sum('quantity')
            && (int)$summary->available === (int)$wanted->sum(fn ($row) => $stockStates[$row->id]['direct_issue_quantity'])
            && (int)$summary->unknown_count === $wanted->filter(fn ($row) => !$stockStates[$row->id]['consistent'])->count()
            && (int)$summary->quarantined === (int)$wanted->sum('quarantined_quantity')
            && (int)$summary->products_count === $wanted->pluck('product_id')->unique()->count(), 'Stock listing MySQL '.$status.' totals are incorrect.');
    }
    foreach (['quantity', 'available', 'quarantined_quantity', 'min_quantity'] as $field) foreach (['asc', 'desc'] as $direction) {
        $value = fn ($row) => $field === 'available' ? $stockStates[$row->id]['direct_issue_quantity'] : $row->$field;
        $expected = $allStocks->sort(function ($a, $b) use ($value, $direction) {
            $missing = ($value($a) === null) <=> ($value($b) === null);
            if ($missing !== 0) return $missing;
            $compared = ($value($a) <=> $value($b)) ?: ($a->id <=> $b->id);
            return $direction === 'asc' ? $compared : -$compared;
        })->pluck('id')->all();
        $listing = new \App\Queries\StockListing(Request::create('/stock', 'GET', ['sort_by' => $field, 'sort_dir' => $direction]));
        check($listing->query()->pluck('stocks.id')->all() === $expected, 'Stock listing MySQL numeric order differs for '.$field.' '.$direction.'.');
    }
    $listing = new \App\Queries\StockListing(Request::create('/stock', 'GET', ['search' => 'STOCK-%_! Dashboard MySQL', 'category_id' => $dashboardCategory->category_id]));
    check($listing->query()->pluck('stocks.id')->all() === [$literalStock->id], 'Stock listing MySQL literal/category search is incorrect.');
    check(hash_equals($beforeStockReads, $queueSnapshot()), 'Stock listing changed inventory or history.');
    echo 'PASS: stock listing MySQL statuses, summaries, numeric sorting and literal/category search'.PHP_EOL;
    $accentProduct = Product::create(['product_name' => 'ÉKEZETES ŐRLŐ MySQL', 'sku' => 'ACCENT-MYSQL', 'price' => 1, 'category_id' => $dashboardCategory->category_id]);
    $searchBefore = $queueSnapshot();
    $search = \App\Support\AppSearch::results($layoutUser, 'ekezetes orlo');
    check(array_column($search['results'], 'url') === [route('products.show', $accentProduct)], 'Global search MySQL accent normalization differs.');
    $search = \App\Support\AppSearch::results($layoutUser, 'stock-%_!');
    check(array_column($search['results'], 'url') === [route('products.show', $literalProduct)], 'Global search MySQL literal matching differs.');
    $tasks = \App\Support\AppTasks::summary($layoutUser);
    check($tasks['returns'] === $summaryExpected['open_count'] && $tasks['returnQuantity'] === $summaryExpected['pending_quantity']
        && $tasks['transfers'] === InventoryTransfer::where('status', 'pending')->get()->count(), 'Topbar tasks differ from current business state.');
    check(hash_equals($searchBefore, $queueSnapshot()), 'Global search or task counts changed business records.');
    echo 'PASS: global search MySQL accents/literals and topbar task counts'.PHP_EOL;
    foreach (['weight', 'slots'] as $limit) {
        $destination = Warehouse::create(['warehouse_name' => 'Capacity destination '.$limit]);
        $location = $destination->storageLocations()->create(['code' => 'R-1', 'name' => 'Raklaphely', 'kind' => 'pallet',
            'max_weight_grams' => $limit === 'weight' ? 100000 : 200000, 'max_pallet_slots' => $limit === 'slots' ? 1 : 2]);
        $jobs = [];
        $balances = [];
        for ($i = 0; $i < 2; $i++) {
            $source = Warehouse::create(['warehouse_name' => "Capacity source $limit $i"]);
            $product = Product::create(['category_id' => $category->category_id, 'product_name' => "Capacity $limit $i", 'sku' => "CAP-$limit-$i", 'price' => 1]);
            $balances[] = Stock::create(['warehouse_id' => $source->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 10]);
            $jobs[] = ['action' => 'start', 'outerSnapshot' => true, 'requestKey' => (string) Str::uuid(),
                'data' => ['from_warehouse_id' => $source->warehouse_id, 'to_warehouse_id' => $destination->warehouse_id,
                    'product_id' => $product->product_id, 'quantity' => 5, 'user_id' => $user->id,
                    'to_location_id' => $location->id, 'cargo_weight_kg' => 60, 'pallet_slots' => 1]];
        }
        check(race($database, [], $jobs, recordBarrier: $destination) === ['OK', 'REJECTED'], 'Concurrent products exceeded shared '.$limit.' capacity.');
        $usage = WarehouseLocation::withCapacityUsage()->findOrFail($location->id);
        check((int) $usage->reserved_weight === 60000 && (int) $usage->reserved_slots === 1, 'Capacity reservation was lost or duplicated.');
        check(array_sum(array_map(fn ($stock) => $stock->fresh()->quantity, $balances)) === 15, 'Rejected reservation changed source stock.');
        echo 'PASS: shared warehouse '.$limit.' capacity across different products and outer snapshots'.PHP_EOL;
    }
    $otherPicker = User::factory()->create(['role' => 'worker']);
    foreach ([false, true] as $outerSnapshot) {
        $suffix = (int) $outerSnapshot;
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => 'Concurrent pick '.$suffix, 'sku' => 'PICK-RACE-'.$suffix, 'price' => 1]);
        $source = Warehouse::create(['warehouse_name' => 'Picking source '.$suffix]);
        $target = Warehouse::create(['warehouse_name' => 'Picking target '.$suffix]);
        $stock = Stock::create(['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 20]);
        $bin = $source->storageLocations()->create(['code' => 'B-01', 'name' => 'Polc', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
        $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => 10, 'cargo_weight_grams' => 5555]);
        $data = [
            'product_id' => $product->getKey(), 'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(),
            'quantity' => 7, 'source_allocations' => [['location_id' => $bin->id, 'quantity' => 7]],
        ];
        $jobs = array_map(
            fn (User $picker) => ['action' => 'start', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string) Str::uuid(), 'data' => $data + ['user_id' => $picker->id]],
            [$user, $otherPicker],
        );

        check(race($database, $product->getKey(), $jobs) === ['OK', 'REJECTED'], 'Two concurrent pickers withdrew the same bin stock.');
        $transfer = InventoryTransfer::where('product_id', $product->getKey())->sole();
        $picked = $transfer->sourceAllocations()->sole();
        $remaining = $bin->placements()->sole();
        check($stock->fresh()->quantity === 13 && $remaining->quantity === 3, 'Concurrent picking left inconsistent warehouse or bin stock.');
        check($picked->quantity === 7 && $picked->warehouse_location_id === $bin->id, 'Concurrent picking left incorrect source history.');
        check($picked->cargo_weight_grams + $remaining->cargo_weight_grams === 5555, 'Concurrent picking did not preserve bin load.');
        check(DB::table('inventory_transfer_requests')->whereIn('request_key', array_column($jobs, 'requestKey'))->count() === 1, 'Rejected picking request left a write.');
        echo 'PASS: concurrent withdrawals from one bin'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }
    $placeWarehouse = Warehouse::create(['warehouse_name' => 'Repeated placement']);
    $placeLocation = $placeWarehouse->storageLocations()->create(['code' => 'P-1', 'name' => 'Polc', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
    $placeProduct = Product::create(['category_id' => $category->category_id, 'product_name' => 'Repeated placement', 'sku' => 'CAP-PLACE', 'price' => 1]);
    Stock::create(['warehouse_id' => $placeWarehouse->warehouse_id, 'product_id' => $placeProduct->product_id, 'quantity' => 10]);
    $placement = ['action' => 'place', 'outerSnapshot' => true, 'id' => $placeLocation->id, 'data' => [
        'request_key' => (string) Str::uuid(), 'product_id' => $placeProduct->product_id, 'user_id' => $user->id,
        'quantity' => 4, 'cargo_weight_kg' => 25,
    ]];
    check(race($database, $placeProduct->product_id, [$placement, $placement]) === ['OK', 'OK'], 'Concurrent placement replay failed.');
    check($placeLocation->placements()->count() === 1 && $placeLocation->placements()->sole()->quantity === 4, 'Concurrent placement replay assigned stock twice.');
    echo "PASS: identical placement with overlapping requests and outer snapshots\n";
    $plan = ['action' => 'floor_plan', 'id' => $placeWarehouse->warehouse_id, 'outerSnapshot' => true,
        'data' => ['version' => 1, 'columns' => 24, 'rows' => 16, 'positions' => [['id' => $placeLocation->id, 'x' => 2, 'y' => 2, 'width' => 3, 'height' => 2]]]];
    $secondPlan = $plan;
    $secondPlan['data']['positions'][0]['x'] = 5;
    check(race($database, [], [$plan, $secondPlan], recordBarrier: $placeWarehouse) === ['OK', 'REJECTED'], 'A stale floor plan overwrote the saved version.');
    check((int) $placeWarehouse->fresh()->floor_version === 2 && in_array($placeLocation->fresh()->floor_x, [2, 5], true), 'Floor plan did not store the winning layout.');
    echo "PASS: two different floor plans with the same version and outer snapshots\n";
    $rackWarehouse = Warehouse::create(['warehouse_name' => 'Concurrent rack']);
    $rack = ['action' => 'storage_designer', 'id' => $rackWarehouse->warehouse_id, 'outerSnapshot' => true,
        'data' => ['version' => 1, 'kind' => 'rack', 'name' => 'Concurrent rack', 'zone' => 'A', 'rack_height' => 2,
            'x' => 0, 'y' => 0, 'width' => 4, 'height' => 2, 'levels' => 2, 'bins' => 2,
            'bin_weight' => 50, 'level_weight' => 60, 'system_weight' => 70]];
    check(race($database, [], [$rack, $rack], recordBarrier: $rackWarehouse) === ['OK', 'REJECTED'], 'Two requests created the same rack layout.');
    $rackBins = $rackWarehouse->storageSystems()->sole()->locations()->orderBy('code')->get();
    check($rackBins->count() === 4 && (int) $rackWarehouse->fresh()->floor_version === 2, 'Concurrent rack creation left duplicate or partial bins.');
    echo "PASS: only one rack is created for the same floor-plan version\n";

    $rackProduct = Product::create(['category_id' => $category->category_id, 'product_name' => 'Rack cargo', 'sku' => 'RACK-CARGO', 'price' => 1]);
    $otherCargo = Product::create(['category_id' => $category->category_id, 'product_name' => 'Other rack cargo', 'sku' => 'OTHER-RACK-CARGO', 'price' => 1]);
    $jobs = [];
    foreach ([$rackProduct, $otherCargo] as $i => $cargo) {
        Stock::create(['warehouse_id' => $rackWarehouse->warehouse_id, 'product_id' => $cargo->product_id, 'quantity' => 10]);
        $jobs[] = ['action' => 'place', 'id' => $rackBins[$i * 2]->id, 'outerSnapshot' => true,
            'data' => ['product_id' => $cargo->product_id, 'quantity' => 1, 'cargo_weight_kg' => 40]];
    }
    check(race($database, [], $jobs, recordBarrier: $rackWarehouse) === ['OK', 'REJECTED'], 'Different bins allowed the total rack weight to exceed the limit.');
    check($rackWarehouse->storageSystems()->sole()->locations()->withSum('placements', 'cargo_weight_grams')->get()->sum('placements_sum_cargo_weight_grams') === 40000, 'The rejected load left stock assigned.');
    echo "PASS: simultaneous loading of different levels honors the shared rack limit\n";

    $bin = $rackBins[1];
    $otherCategory = Category::create(['category_name' => 'Concurrent bin category']);
    $reservationSource = Warehouse::create(['warehouse_name' => 'Concurrent bin reservation source']);
    Stock::create(['warehouse_id' => $reservationSource->warehouse_id, 'product_id' => $rackProduct->product_id, 'quantity' => 10]);
    $configure = ['action' => 'bin_configuration', 'id' => $rackWarehouse->warehouse_id, 'system' => $bin->system_id, 'bin' => $bin->id,
        'outerSnapshot' => true, 'data' => ['lock_version' => 1, 'width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100,
            'max_weight_kg' => 50, 'categories' => [$otherCategory->getKey()], 'active' => true]];
    $reserve = ['action' => 'start', 'outerSnapshot' => true, 'data' => ['product_id' => $rackProduct->product_id,
        'from_warehouse_id' => $reservationSource->getKey(), 'to_warehouse_id' => $rackWarehouse->getKey(),
        'to_location_id' => $bin->id, 'cargo_weight_kg' => 10, 'quantity' => 1, 'user_id' => $user->id]];
    check(race($database, [], [$configure, $reserve], recordBarrier: $rackWarehouse) === ['OK', 'REJECTED'], 'An incompatible category rule and reservation both succeeded.');
    check($bin->allowedCategories()->count() + $bin->reservations()->count() === 1, 'The bin has an incompatible reservation.');
    echo "PASS: a bin category restriction and an incompatible reservation cannot both succeed\n";
    $requester = User::factory()->create(['role' => 'worker']);
    $supervisor = User::factory()->create(['role' => 'worker']);
    $secondSupervisor = User::factory()->create(['role' => 'worker']);
    $intentSource = Warehouse::create(['warehouse_name' => 'Intent source']);
    $intentDestination = Warehouse::create(['warehouse_name' => 'Intent destination']);
    $intentSource->memberships()->create(['user_id' => $requester->id, 'role' => 'requester']);
    $intentSource->memberships()->create(['user_id' => $supervisor->id, 'role' => 'supervisor']);
    $intentSource->memberships()->create(['user_id' => $secondSupervisor->id, 'role' => 'supervisor']);
    $intentProduct = Product::create(['category_id' => $category->category_id, 'product_name' => 'Concurrent intents', 'sku' => 'INTENT-RACE', 'price' => 1]);
    $intentService = app(TransferIntentService::class);
    $intentData = ['product_id' => $intentProduct->getKey(), 'from_warehouse_id' => $intentSource->getKey(),
        'to_warehouse_id' => $intentDestination->getKey(), 'quantity' => 12];
    $intentRaceCount = 0;

    foreach ([false, true] as $outerSnapshot) {
        foreach (['approve versus reject', 'duplicate approval', 'same key with a different actor'] as $scenario) {
            $result = $intentService->create($requester, $intentData + ['operation_key' => (string) Str::uuid()]);
            $intent = TransferIntent::findOrFail($result['intent']['id']);
            $approve = ['action' => 'transfer_intent', 'operation' => 'approve', 'id' => $intent->id, 'actor' => $supervisor->id,
                'outerSnapshot' => $outerSnapshot, 'data' => ['version' => 1, 'operation_key' => (string) Str::uuid()]];
            $other = $approve;
            if ($scenario === 'approve versus reject') {
                $other['operation'] = 'reject';
                $other['actor'] = $secondSupervisor->id;
                $other['data'] = ['version' => 1, 'operation_key' => (string) Str::uuid(), 'reason' => 'Párhuzamos elutasítás'];
            }
            if ($scenario === 'same key with a different actor') $other['actor'] = $secondSupervisor->id;

            $outcomes = race($database, [], [$approve, $other], recordBarrier: $intent);
            $expected = $scenario === 'duplicate approval' ? ['OK', 'OK'] : ['OK', 'REJECTED'];
            check($outcomes === $expected, $scenario.': incorrect concurrent decisions.');
            $intent->refresh();
            $decision = $intent->events()->where('event_type', '!=', 'created')->sole();
            check($intent->version === 2 && $intent->status === $decision->event_type
                && $decision->from_status === 'pending_approval' && $decision->to_status === $intent->status,
                $scenario.': intent version and decision history diverged.');
            check($intent->events()->count() === 2, $scenario.': a duplicate or incomplete event history was written.');
            $intentRaceCount++;
            echo 'PASS: intent '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }

    foreach (['same payload', 'different payload'] as $scenario) {
        $key = (string) Str::uuid();
        $create = ['action' => 'transfer_intent', 'operation' => 'create', 'actor' => $requester->id, 'outerSnapshot' => true,
            'data' => $intentData + ['operation_key' => $key]];
        $other = $create;
        if ($scenario === 'different payload') $other['data']['quantity'] = 13;

        $before = TransferIntent::count();
        $outcomes = race($database, $intentProduct->getKey(), [$create, $other]);
        $expected = $scenario === 'same payload' ? ['OK', 'OK'] : ['OK', 'REJECTED'];
        check($outcomes === $expected, $scenario.': incorrect concurrent creation outcomes.');
        $event = TransferWorkflowEvent::where('operation_key', $key)->sole();
        check(TransferIntent::count() === $before + 1 && $event->intent->events()->count() === 1
            && $event->actor_id === $requester->id && $event->event_type === 'created',
            $scenario.': concurrent creation left a duplicate intent or missing audit event.');
        $intentRaceCount++;
        echo 'PASS: concurrent intent creation with '.$scenario.PHP_EOL;
    }
    check(Stock::where('product_id', $intentProduct->getKey())->doesntExist()
        && InventoryTransfer::where('product_id', $intentProduct->getKey())->doesntExist(), 'Intent decisions booked inventory.');
    $reservationRaceCount = 0;
    $reservationService = app(TransferStockReservationService::class);
    $availability = app(WarehouseStockAvailability::class);
    foreach ([false, true] as $outerSnapshot) {
        foreach (['two reservations', 'reservation versus out', 'reservation versus shipment', 'release versus cancel'] as $index => $scenario) {
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario,
                'sku' => 'RES-RACE-'.$index.'-'.(int) $outerSnapshot, 'price' => 1]);
            $stock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 200]);
            $createApproved = function () use ($intentService, $requester, $supervisor, $intentData, $product) {
                $created = $intentService->create($requester, array_replace($intentData, [
                    'product_id' => $product->getKey(), 'quantity' => 150, 'operation_key' => (string) Str::uuid(),
                ]));
                $intent = TransferIntent::findOrFail($created['intent']['id']);
                $intentService->approve($supervisor, $intent, ['version' => 1, 'operation_key' => (string) Str::uuid()]);

                return $intent->refresh();
            };
            $job = fn (string $operation, TransferIntent $intent) => [
                'action' => 'stock_reservation', 'operation' => $operation, 'actor' => $supervisor->id, 'id' => $intent->id,
                'outerSnapshot' => $outerSnapshot, 'data' => ['version' => $intent->version, 'operation_key' => (string) Str::uuid(), 'reason' => 'Teszt'],
            ];
            $intent = $createApproved();
            $reserve = $job('reserve', $intent);
            $shipment = null;
            $barrier = null;
            switch ($scenario) {
                case 'two reservations':
                    $jobs = [$reserve, $job('reserve', $createApproved())];
                    break;
                case 'reservation versus out':
                    $jobs = [$reserve, ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'product_id' => $product->getKey(), 'warehouse_id' => $intentSource->getKey(),
                        'user_id' => $user->id, 'type' => 'out', 'quantity' => 100,
                    ]]];
                    break;
                case 'reservation versus shipment':
                    $shipment = app(ShipmentService::class)->save([
                        'reference' => $scenario, 'origin_warehouse_id' => $intentSource->getKey(),
                        'stops' => [['warehouse_id' => $intentDestination->getKey(), 'items' => [['product_id' => $product->getKey(), 'quantity' => 100]]]],
                    ], $user->id);
                    $jobs = [$reserve, ['action' => 'shipment_start', 'outerSnapshot' => $outerSnapshot, 'id' => $shipment->id, 'actor' => $user->id]];
                    break;
                case 'release versus cancel':
                    $reservationService->reserve($supervisor, $intent, $reserve['data']);
                    $intent->refresh();
                    $jobs = [$job('release', $intent), $job('cancel', $intent)];
                    $barrier = $intent;
                    break;
            }

            $results = race($database, $product->getKey(), $jobs, recordBarrier: $barrier);
            check($results === ['OK', 'REJECTED'], $scenario.': competing operations did not produce a single winner.');
            $snapshot = $availability->snapshot($intentSource->getKey(), $product->getKey());
            $balance = [$stock->refresh()->quantity, $snapshot['source_reserved_quantity'], $snapshot['free_quantity']];
            check($snapshot['consistent'], $scenario.': winning operation left inconsistent stock.');
            if ($scenario === 'two reservations') {
                check($balance === [200, 150, 50], $scenario.': reserve did not leave 50 free without changing physical stock.');
                check(DB::table('transfer_stock_reservations')->where('stock_id', $stock->id)->count() === 1, 'Multiple reservation records survived.');
            } elseif ($scenario === 'release versus cancel') {
                check($balance === [200, 0, 200], $scenario.': release changed physical inventory or left a hold.');
                check($intent->refresh()->version === 4 && $intent->events()->count() === 4, 'Both release and cancel changed the same intent version.');
                check(DB::table('transfer_stock_reservations')->where('stock_id', $stock->id)->where('status', 'released')->count() === 1, 'Missing released reservation.');
            } else {
                check(in_array($balance, [[200, 150, 50], [100, 0, 100]], true), $scenario.': reservation and issue both took effect.');
                if ($shipment) {
                    $status = $balance[0] === 100 ? 'in_transit' : 'draft';
                    check($shipment->refresh()->status === $status, 'Shipment status disagrees with stock posting.');
                }
            }
            $reservationRaceCount++;
            echo 'PASS: '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $pickingRaceCount = 0;
    $tasks = app(WarehouseTransferTaskService::class);
    foreach ([false, true] as $outerSnapshot) {
        foreach (['same task', 'same pick replay', 'pick versus out', 'two tasks competing for one bin', 'two compatible tasks',
            'same unassigned pick replay', 'two tasks competing for unassigned stock'] as $index => $scenario) {
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario,
                'sku' => 'TASK-RACE-'.$index.'-'.(int) $outerSnapshot, 'price' => 1]);
            $stock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 200]);
            $bin = $intentSource->storageLocations()->create(['code' => $product->sku, 'name' => 'Polc', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
            $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => 150, 'cargo_weight_grams' => 15000]);
            $createTask = static function (int $quantity) use ($intentService, $reservationService, $tasks, $requester, $supervisor, $intentData, $product): TransferIntent {
                $result = $intentService->create($requester, array_replace($intentData, [
                    'product_id' => $product->getKey(), 'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
                ]));
                $intent = TransferIntent::findOrFail($result['intent']['id']);
                $command = static fn () => ['operation_key' => (string) Str::uuid(), 'version' => $intent->refresh()->version];
                $intentService->approve($supervisor, $intent, $command());
                $reservationService->reserve($supervisor, $intent, $command());
                $tasks->create($supervisor, $intent, $command());

                return $intent->refresh();
            };
            $manual = in_array($scenario, ['same unassigned pick replay', 'two tasks competing for unassigned stock'], true);
            $confirmation = ['found' => true, 'description' => 'Kiadási terület mellett.'];
            $makePick = static fn (TransferIntent $intent, User $actor, int $quantity) => [
                'action' => 'pick_task', 'actor' => $actor->id, 'id' => $intent->id, 'outerSnapshot' => $outerSnapshot,
                'data' => ['operation_key' => (string) Str::uuid(), 'version' => $intent->version, 'quantity' => $quantity,
                    'source_allocations' => [[
                        'source_type' => $manual ? 'unassigned' : 'location',
                        'location_id' => $manual ? null : $bin->id, 'quantity' => $quantity,
                    ]]] + ($manual ? ['unassigned_confirmation' => $confirmation] : []),
            ];
            $differentTasks = in_array($scenario, ['two tasks competing for one bin', 'two compatible tasks', 'two tasks competing for unassigned stock'], true);
            $intent = $createTask($differentTasks ? 100 : 150);
            $pick = $makePick($intent, $supervisor, $manual ? 30 : 100);
            $other = match ($scenario) {
                'same task' => $makePick($intent, $secondSupervisor, 100),
                'same pick replay', 'same unassigned pick replay' => $pick,
                'pick versus out' => ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'data' => [
                    'product_id' => $product->getKey(), 'warehouse_id' => $intentSource->getKey(), 'user_id' => $user->id,
                    'type' => 'out', 'quantity' => 51,
                ]],
                'two tasks competing for one bin' => $makePick($createTask(100), $secondSupervisor, 100),
                'two compatible tasks' => $makePick($createTask(100), $secondSupervisor, 50),
                'two tasks competing for unassigned stock' => $makePick($createTask(100), $secondSupervisor, 30),
            };
            $successes = in_array($scenario, ['same pick replay', 'same unassigned pick replay', 'two compatible tasks'], true) ? ['OK', 'OK'] : ['OK', 'REJECTED'];
            check(race($database, $product->getKey(), [$pick, $other]) === $successes, $scenario.': unexpected pick outcomes.');

            $snapshot = $availability->snapshot($intentSource->getKey(), $product->getKey());
            $staged = match ($scenario) {
                'two compatible tasks' => 150,
                'same unassigned pick replay', 'two tasks competing for unassigned stock' => 30,
                default => 100,
            };
            $reserved = ($differentTasks ? 200 : 150) - $staged;
            check($snapshot['consistent'] && $snapshot['source_reserved_quantity'] === $reserved
                && $snapshot['staged_quantity'] === $staged && $snapshot['free_quantity'] === 200 - $reserved - $staged,
                $scenario.': staged, reserved or free balance changed incorrectly.');
            check($stock->refresh()->quantity === 200, $scenario.': picking changed physical inventory.');
            check((int) $bin->placements()->sum('quantity') === ($manual ? 150 : 150 - $staged), $scenario.': bin balance was changed twice or lost.');
            $taskIds = DB::table('warehouse_transfer_tasks')->where('stock_id', $stock->id)->pluck('id');
            $allocations = DB::table('warehouse_transfer_allocations')->whereIn('warehouse_transfer_task_id', $taskIds);
            check((int) (clone $allocations)->sum('quantity') === $staged, $scenario.': pick has incomplete or duplicate allocation history.');
            check($allocations->pluck('transfer_workflow_event_id')->unique()->count() === ($staged === 150 ? 2 : 1),
                $scenario.': incorrect number of successful pick audit events.');
            if ($manual) {
                $allocation = $allocations->sole();
                check($allocation->source_type === 'unassigned' && $allocation->warehouse_location_id === null && $snapshot['unassigned_quantity'] === 20,
                    $scenario.': incorrect source type, bin or unassigned balance.');
                $event = TransferWorkflowEvent::findOrFail($allocation->transfer_workflow_event_id);
                $audit = $event->metadata['unassigned_confirmation'] ?? null;
                check(($audit['found'] ?? null) === true && ($audit['description'] ?? null) === $confirmation['description']
                    && (int) $event->actor_id === (int) $allocation->performed_by,
                    $scenario.': the confirmed physical source or performer is missing from audit.');
            }
            $pickingRaceCount++;
            echo 'PASS: picking '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $manualIssueRaceCount = 0;
    foreach ([false, true] as $outerSnapshot) {
        foreach (['two issues', 'duplicate request', 'issue versus reservation', 'issue versus pick'] as $index => $scenario) {
            $sku = 'MANUAL-ISSUE-RACE-'.$index.'-'.(int) $outerSnapshot;
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario, 'sku' => $sku, 'price' => 1]);
            $stock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 200]);
            $bin = $intentSource->storageLocations()->create(['code' => $sku, 'name' => 'Polc', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
            $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => 100, 'cargo_weight_grams' => 10000]);
            $quantity = $scenario === 'issue versus pick' ? 40 : 60;
            $allocation = fn (int $quantity) => ['source_type' => 'location', 'location_id' => $bin->id, 'quantity' => $quantity];
            $issue = ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string) Str::uuid(), 'data' => [
                'product_id' => $product->getKey(), 'warehouse_id' => $intentSource->getKey(), 'user_id' => $user->id,
                'type' => 'out', 'quantity' => $quantity, 'source_allocations' => [$allocation($quantity)],
            ]];
            $other = $issue;
            if ($scenario === 'two issues') $other['requestKey'] = (string) Str::uuid();
            if (in_array($scenario, ['issue versus reservation', 'issue versus pick'], true)) {
                $result = $intentService->create($requester, array_replace($intentData, [
                    'product_id' => $product->getKey(), 'quantity' => 150, 'operation_key' => (string) Str::uuid(),
                ]));
                $intent = TransferIntent::findOrFail($result['intent']['id']);
                $command = fn () => ['version' => $intent->refresh()->version, 'operation_key' => (string) Str::uuid()];
                $intentService->approve($supervisor, $intent, $command());
                $other = ['action' => 'stock_reservation', 'operation' => 'reserve', 'id' => $intent->id,
                    'actor' => $supervisor->id, 'outerSnapshot' => $outerSnapshot, 'data' => $command()];
                if ($scenario === 'issue versus pick') {
                    $reservationService->reserve($supervisor, $intent, $command());
                    $tasks->create($supervisor, $intent, $command());
                    $other['action'] = 'pick_task';
                    $other['data'] = $command() + ['quantity' => 100, 'source_allocations' => [$allocation(100)]];
                }
            }
            $expected = $scenario === 'duplicate request' ? ['OK', 'OK'] : ['OK', 'REJECTED'];
            check(race($database, $product->getKey(), [$issue, $other]) === $expected, $scenario.': unexpected issue outcomes.');

            $movements = InventoryMovement::where('product_id', $product->getKey())->with('issueSources')->get();
            $issued = (int) $movements->sum('quantity');
            check($movements->count() <= 1 && ($issued === 0 || $issued === $quantity), $scenario.': duplicate or partial movement.');
            $snapshot = $availability->snapshot($intentSource->getKey(), $product->getKey());
            $reserved = $snapshot['source_reserved_quantity'];
            $staged = $snapshot['staged_quantity'];
            $balances = [$issued, $reserved, $staged];
            $allowed = match ($scenario) {
                'issue versus pick' => [[40, 150, 0], [0, 50, 100]],
                'issue versus reservation' => [[60, 0, 0], [0, 150, 0]],
                default => [[60, 0, 0]],
            };
            check(in_array($balances, $allowed, true) && $snapshot['consistent'] && $stock->refresh()->quantity === 200 - $issued
                && (int) $bin->placements()->sum('quantity') === 100 - $issued - $staged, $scenario.': stock, holds and bin do not reconcile.');
            if ($issued > 0) {
                $movement = $movements->sole();
                $source = $movement->issueSources->sole();
                check($movement->stock_before === 200 && $movement->stock_after === 200 - $issued
                    && $source->warehouse_location_id === $bin->id && $source->quantity === $issued,
                    $scenario.': inconsistent or missing issue audit.');
            }
            $requestKeys = array_unique(array_filter([$issue['requestKey'], $other['requestKey'] ?? null]));
            check(DB::table('inventory_movement_requests')->whereIn('request_key', $requestKeys)->count() === $movements->count(),
                $scenario.': rejected or replayed issue left another claim.');
            $manualIssueRaceCount++;
            echo 'PASS: manual bin issue '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $planningRaceCount = 0;
    $planner = app(ShipmentPlanningService::class);
    $planProduct = Product::create(['category_id' => $category->getKey(), 'product_name' => 'Concurrent shipment planning', 'sku' => 'PLAN-RACE', 'price' => 1]);
    $planStock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $planProduct->getKey(), 'quantity' => 500]);
    $makeApproved = function () use ($intentService, $requester, $supervisor, $intentData, $planProduct): TransferIntent {
        $creation = $intentService->create($requester, array_replace($intentData, [
            'product_id' => $planProduct->getKey(), 'quantity' => 20, 'operation_key' => (string) Str::uuid(),
        ]));
        $intent = TransferIntent::findOrFail($creation['intent']['id']);
        $intentService->approve($supervisor, $intent, ['operation_key' => (string) Str::uuid(), 'version' => $intent->version]);

        return $intent->refresh();
    };
    $makePlan = function (string $reference, array $intentIds = []) use ($planner, $supervisor, $intentSource): Shipment {
        $result = $planner->create($supervisor, [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $intentSource->getKey(),
            'reference' => $reference, 'intent_ids' => $intentIds,
        ]);

        return Shipment::findOrFail($result['shipment']['id']);
    };
    foreach ([false, true] as $outerSnapshot) {
        foreach (['cross-plan assignment', 'disjoint cross-plan assignments', 'attach versus detach', 'same attachment replay',
            'attach versus intent cancellation'] as $scenario) {
            $intent = $makeApproved();
            $plan = $makePlan($scenario, $scenario === 'attach versus detach' ? [$intent->id] : []);
            $disjoint = $scenario === 'disjoint cross-plan assignments';
            $otherIntent = $scenario === 'attach versus detach' || $disjoint ? $makeApproved() : $intent;
            $otherPlan = $scenario === 'cross-plan assignment' || $disjoint ? $makePlan('Competing plan') : $plan;
            $beforeAudit = ShipmentPlanOperation::count();
            $beforeBalances = $availability->snapshot($intentSource->getKey(), $planProduct->getKey());
            $attach = fn (User $actor, Shipment $target, TransferIntent $cargo) => [
                'action' => 'shipment_plan', 'operation' => 'attach', 'actor' => $actor->id,
                'ship' => $target->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                    'operation_key' => (string) Str::uuid(), 'version' => $target->version, 'intent_ids' => [$cargo->id],
                ],
            ];
            $first = $attach($supervisor, $plan, $otherIntent);
            $second = match ($scenario) {
                'cross-plan assignment' => $attach($secondSupervisor, $otherPlan, $intent),
                'disjoint cross-plan assignments' => $attach($supervisor, $otherPlan, $intent),
                'attach versus detach' => [
                    'action' => 'shipment_plan', 'operation' => 'detach', 'actor' => $secondSupervisor->id,
                    'ship' => $plan->id, 'intent' => $intent->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'operation_key' => (string) Str::uuid(), 'version' => $plan->version, 'reason' => 'Másik fuvar szükséges.',
                    ],
                ],
                'same attachment replay' => $first,
                'attach versus intent cancellation' => [
                    'action' => 'stock_reservation', 'operation' => 'cancel', 'actor' => $secondSupervisor->id,
                    'id' => $intent->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'operation_key' => (string) Str::uuid(), 'version' => $intent->version, 'reason' => 'Az igény megszűnt.',
                    ],
                ],
            };
            $barrier = in_array($scenario, ['attach versus detach', 'same attachment replay'], true) ? $plan : $intent;
            $outcomes = $disjoint ? race($database, [], [$first, $second], userBarrier: $supervisor->id)
                : race($database, [], [$first, $second], recordBarrier: $barrier);
            $auditCount = ShipmentPlanOperation::count() - $beforeAudit;
            $replay = $scenario === 'same attachment replay';
            check($outcomes === ($replay || $disjoint ? ['OK', 'OK'] : ['OK', 'REJECTED']), $scenario.': concurrent plan mutations had unexpected results.');
            $active = ShipmentIntentAssignment::where('transfer_intent_id', $intent->id)->active()->get();
            if ($scenario === 'attach versus detach') {
                $secondActive = ShipmentIntentAssignment::where('transfer_intent_id', $otherIntent->id)->active()->count();
                check(($active->count() === 1 && $secondActive === 1) || ($active->isEmpty() && $secondActive === 0),
                    $scenario.': concurrent commands left a mixed plan despite matching old version.');
            } elseif ($scenario === 'attach versus intent cancellation') {
                check(($intent->refresh()->status === 'approved' && $active->count() === 1) || ($intent->status === 'cancelled' && $active->isEmpty()),
                    $scenario.': an intent can be cancelled and active on a plan at the same time.');
            } elseif ($disjoint) {
                check((int) $active->sole()->shipment_id === $otherPlan->id
                    && (int) ShipmentIntentAssignment::where('transfer_intent_id', $otherIntent->id)->active()->sole()->shipment_id === $plan->id,
                    $scenario.': separate assignments were skipped or crossed between plans.');
            } else {
                check($active->count() === 1, $scenario.': concurrent assignment left duplicate or missing active rows.');
            }
            $cancelWon = $scenario === 'attach versus intent cancellation' && $intent->status === 'cancelled';
            check($auditCount === ($cancelWon ? 0 : ($disjoint ? 2 : 1)), $scenario.': duplicate or missing plan audit event.');
            check($plan->fresh()->version + ($otherPlan->id === $plan->id ? 0 : $otherPlan->fresh()->version) ===
                $plan->version + ($otherPlan->id === $plan->id ? 0 : $otherPlan->version) + $auditCount,
                $scenario.': shipment version does not match the successful audit operation.');
            check($availability->snapshot($intentSource->getKey(), $planProduct->getKey()) === $beforeBalances
                && $planStock->refresh()->quantity === 500 && InventoryTransfer::where('product_id', $planProduct->getKey())->doesntExist(),
                $scenario.': freight planning booked inventory.');
            $planningRaceCount++;
            echo 'PASS: shipment planning '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $cargoRaceCount = 0;
    $cargoPreparation = app(ShipmentCargoPreparation::class);
    $readiness = app(ShipmentPlanReadiness::class);
    $vehicle = Vehicle::create(['name' => 'Nagy teherautó', 'registration_number' => 'TEST-120',
        'payload_capacity_grams' => 1200000, 'pallet_slots_capacity' => 6, 'active' => true]);
    $replacement = Vehicle::create(['name' => 'Kis teherautó', 'registration_number' => 'TEST-080',
        'payload_capacity_grams' => 800000, 'pallet_slots_capacity' => 2, 'active' => true]);
    $physicalTables = ['stocks', 'inventory_transfers', 'inventory_movements', 'warehouse_placements', 'inventory_transfer_source_allocations'];
    $pickingTables = ['transfer_stock_reservations', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations'];
    $stateOf = static fn (array $tables): array => array_map(
        static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(JSON_THROW_ON_ERROR), $tables,
    );
    $command = static fn ($record, array $data = []): array => $data + [
        'operation_key' => (string) Str::uuid(), 'version' => $record->version,
    ];
    foreach ([false, true] as $outerSnapshot) {
        foreach (['two confirmations', 'same confirmation replay', 'same key with changed cargo', 'confirmation versus vehicle change',
            'confirmation versus picking'] as $index => $scenario) {
            $sku = 'CARGO-RACE-'.$index.'-'.(int) $outerSnapshot;
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario, 'sku' => $sku, 'price' => 1]);
            Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 100]);
            Stock::create(['warehouse_id' => $intentDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 7]);
            $bin = $intentDestination->storageLocations()->create(['code' => $sku, 'name' => 'Foglalt raklaphely', 'kind' => 'pallet',
                'max_weight_grams' => 200000, 'max_pallet_slots' => 4]);
            $service->startTransfer([
                'product_id' => $product->getKey(), 'from_warehouse_id' => $intentSource->getKey(), 'to_warehouse_id' => $intentDestination->getKey(),
                'quantity' => 5, 'user_id' => $supervisor->id, 'to_location_id' => $bin->id, 'cargo_weight_grams' => 12000, 'pallet_slots' => 1,
            ]);
            $created = $intentService->create($requester, array_replace($intentData, [
                'product_id' => $product->getKey(), 'quantity' => 20, 'operation_key' => (string) Str::uuid(),
            ]));
            $intent = TransferIntent::findOrFail($created['intent']['id']);
            $intentService->approve($supervisor, $intent, $command($intent));
            $reservationService->reserve($supervisor, $intent, $command($intent->refresh()));
            $tasks->create($supervisor, $intent, $command($intent->refresh()));
            $pick = static fn (int $amount): array => $command($intent->refresh(), [
                'quantity' => $amount, 'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $amount]],
                'unassigned_confirmation' => ['found' => true, 'description' => 'Kézi kiadási terület.'],
            ]);
            $tasks->pick($supervisor, $intent, $pick(15));
            $plan = $makePlan($scenario, [$intent->id]);
            $planner->selectVehicle($supervisor, $plan, $command($plan, ['vehicle_id' => $vehicle->id]));
            $planner->saveCargoItem($supervisor, $plan, $plan->intentAssignments()->active()->sole()->id, $command($plan->refresh(), [
                'source' => 'manual', 'gross_weight_grams' => 1000000, 'confirmed' => true,
            ]));
            $plan->refresh();
            $basis = $cargoPreparation->details($plan, $plan->intentAssignments()->active()->get())['basis_revision'];
            $first = ['action' => 'shipment_plan', 'operation' => 'confirm_cargo', 'actor' => $supervisor->id,
                'ship' => $plan->id, 'outerSnapshot' => $outerSnapshot, 'data' => $command($plan, [
                    'basis_revision' => $basis, 'gross_weight_grams' => 1000000, 'pallet_slots' => 4,
                ])];
            $second = match ($scenario) {
                'same confirmation replay' => $first,
                'confirmation versus vehicle change' => array_replace($first, [
                    'operation' => 'select_vehicle', 'actor' => $secondSupervisor->id, 'data' => $command($plan, ['vehicle_id' => $replacement->id]),
                ]),
                'confirmation versus picking' => ['action' => 'pick_task', 'actor' => $secondSupervisor->id,
                    'outerSnapshot' => $outerSnapshot, 'id' => $intent->id, 'data' => $pick(5)],
                default => array_replace($first, ['data' => array_replace($first['data'], [
                    'gross_weight_grams' => 1025000, 'reason' => 'A mérés további rögzítőanyagot igazolt.',
                ])]),
            };
            if ($scenario === 'two confirmations') {
                $second['actor'] = $secondSupervisor->id;
                $second['data']['operation_key'] = (string) Str::uuid();
            }
            $picking = $scenario === 'confirmation versus picking';
            $tables = $picking ? $physicalTables : [...$physicalTables, ...$pickingTables];
            $beforeState = $stateOf($tables);
            $beforeBalance = $availability->snapshot($intentSource->getKey(), $product->getKey());
            check($beforeBalance['physical_quantity'] === 95 && $beforeBalance['source_reserved_quantity'] === 5
                && $beforeBalance['staged_quantity'] === 15, $scenario.': the stock fixture is not exercising a hold and staged inventory.');
            $beforeAudit = $plan->planOperations()->max('id');
            $beforeVersion = $plan->version;

            $outcomes = race($database, [], [$first, $second], recordBarrier: $picking ? $intent : $plan);
            $plan->refresh();
            $confirmation = ShipmentCargoConfirmation::where('shipment_id', $plan->id)->first();
            $events = $plan->planOperations()->where('id', '>', $beforeAudit)->get();
            $currentBasis = $cargoPreparation->details($plan, $plan->intentAssignments()->active()->get())['basis_revision'];
            $current = $readiness->assess($plan, $plan->intentAssignments()->active()->with(['intent', 'stop'])->get());
            $balance = $availability->snapshot($intentSource->getKey(), $product->getKey());
            if ($picking) {
                check($outcomes === ($confirmation ? ['OK', 'OK'] : ['OK', 'REJECTED']) && $currentBasis !== $basis
                    && (!$confirmation || $confirmation->basis_revision === $basis) && !$current['cargo_ready'],
                    $scenario.': a confirmation survived a change to the staged quantity.');
                check($balance['staged_quantity'] === 20 && $balance['source_reserved_quantity'] === 0 && $current['stock_ready'],
                    $scenario.': the winning pick did not move the remaining reserved pieces into staging.');
            } else {
                check($outcomes === ($scenario === 'same confirmation replay' ? ['OK', 'OK'] : ['OK', 'REJECTED'])
                    && $balance === $beforeBalance && !$current['stock_ready'], $scenario.': concurrent mutations did not produce the expected winner.');
                check(!$confirmation || ($confirmation->basis_revision === $currentBasis && $current['cargo_ready']),
                    $scenario.': the confirmation has an inconsistent basis.');
            }
            $expectedEvents = $picking && !$confirmation ? 0 : 1;
            check($events->count() === $expectedEvents && $plan->version === $beforeVersion + $expectedEvents,
                $scenario.': duplicate audit or a version that disagrees with the successful operation.');
            if ($confirmation) {
                $event = $events->sole();
                $winningPayload = $event->actor_id === $second['actor'] && $second['actor'] !== $first['actor'] ? $second['data'] : $first['data'];
                $allowedWeights = $scenario === 'same key with changed cargo' ? [1000000, 1025000] : [$winningPayload['gross_weight_grams']];
                check($event->action === 'confirm_cargo' && (int) $confirmation->confirmed_by === (int) $event->actor_id
                    && in_array((int) $confirmation->gross_weight_grams, $allowedWeights, true) && (int) $confirmation->pallet_slots === 4
                    && $plan->vehicle_id === $vehicle->id, $scenario.': the winner did not persist the confirmed load and vehicle together.');
            } elseif (!$picking) {
                check($events->sole()->action === 'select_vehicle' && $plan->vehicle_id === $replacement->id
                    && !$current['cargo_ready'], $scenario.': replacing a vehicle silently confirmed stale cargo.');
            }
            $capacity = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
            check($stateOf($tables) === $beforeState && $capacity->freeWeight() === 188000 && $capacity->freeSlots() === 3
                && $plan->status === 'draft' && $plan->started_at === null && $current['can_dispatch'] === $current['dispatch_prerequisites_met'],
                $scenario.': the cargo operation booked inventory, changed the destination reservation or started the shipment.');
            $cargoRaceCount++;
            echo 'PASS: shipment cargo '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $dispatchRaceCount = 0;
    $dispatchAdmin = User::factory()->create(['role' => 'admin']);
    $secondDispatchDestination = Warehouse::create(['warehouse_name' => 'Dispatch second destination']);
    $prepareDispatchIntent = static function (Product $product, Warehouse $destination, int $quantity, int $picked) use (
        $intentService, $requester, $supervisor, $intentData, $reservationService, $tasks, $command,
    ): TransferIntent {
        $created = $intentService->create($requester, array_replace($intentData, [
            'product_id' => $product->getKey(), 'to_warehouse_id' => $destination->getKey(),
            'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
        ]));
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $intentService->approve($supervisor, $intent, $command($intent));
        $reservationService->reserve($supervisor, $intent, $command($intent->refresh()));
        $tasks->create($supervisor, $intent, $command($intent->refresh()));
        $tasks->pick($supervisor, $intent, $command($intent->refresh(), [
            'quantity' => $picked, 'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $picked]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'Kiadási gyűjtőhely.'],
        ]));

        return $intent->refresh();
    };
    $prepareDispatchPlan = static function (string $reference, Vehicle $vehicle, array $intents) use (
        $makePlan, $planner, $supervisor, $command, $cargoPreparation,
    ): Shipment {
        $plan = $makePlan($reference, array_map(fn (TransferIntent $intent) => $intent->id, $intents));
        $planner->selectVehicle($supervisor, $plan, $command($plan, ['vehicle_id' => $vehicle->id]));
        $assignments = $plan->intentAssignments()->active()->with('intent')->orderBy('id')->get();
        $weight = 0;
        foreach ($assignments as $assignment) {
            $lineWeight = $assignment->intent->quantity * 10000;
            $planner->saveCargoItem($supervisor, $plan, $assignment->id, $command($plan->refresh(), [
                'source' => 'manual', 'gross_weight_grams' => $lineWeight, 'confirmed' => true,
            ]));
            $weight += $lineWeight;
        }
        $basis = $cargoPreparation->details($plan->refresh(), $assignments)['basis_revision'];
        $planner->confirmCargo($supervisor, $plan, $command($plan, [
            'basis_revision' => $basis, 'gross_weight_grams' => $weight, 'pallet_slots' => 2,
        ]));

        return $plan->refresh();
    };

    foreach ([false, true] as $outerSnapshot) {
        foreach (['double dispatch', 'same dispatch replay', 'detach', 'reorder', 'change vehicle', 'edit cargo', 'resize vehicle',
            'issue free quantity', 'issue protected quantity', 'finish picking', 'two plans for one stock', 'latest vehicle capacity'] as $index => $scenario) {
            $sku = 'DISPATCH-RACE-'.$index.'-'.(int) $outerSnapshot;
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario, 'sku' => $sku, 'price' => 1]);
            $sourceStock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 80]);
            $destinationStock = Stock::create(['warehouse_id' => $intentDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 7]);
            $secondDestinationStock = Stock::create(['warehouse_id' => $secondDispatchDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 9]);
            $truck = Vehicle::create(['name' => 'Dispatch '.$sku, 'payload_capacity_grams' => 1200000, 'pallet_slots_capacity' => 6]);
            $twoPlans = $scenario === 'two plans for one stock';
            $reorder = $scenario === 'reorder';
            $partial = $scenario === 'finish picking';
            $mainQuantity = $twoPlans || $reorder ? 30 : 65;
            $intent = $prepareDispatchIntent($product, $intentDestination, $mainQuantity, $partial ? 64 : $mainQuantity);
            $otherIntent = $twoPlans || $reorder ? $prepareDispatchIntent($product, $secondDispatchDestination, 35, 35) : null;
            $intents = $otherIntent ? [$intent, $otherIntent] : [$intent];
            $plan = $prepareDispatchPlan($scenario, $truck, $reorder ? $intents : [$intent]);
            $otherPlan = $twoPlans ? $prepareDispatchPlan('Second departure', $truck, [$otherIntent]) : null;
            $planIds = $otherPlan ? [$plan->id, $otherPlan->id] : [$plan->id];
            $assignment = $plan->intentAssignments()->active()->where('transfer_intent_id', $intent->id)->sole();
            $reversedStops = $reorder ? $plan->stops()->pluck('id')->reverse()->values()->all() : [];
            $dispatch = static fn (Shipment $target, int $quantity): array => [
                'action' => 'shipment_plan', 'operation' => 'dispatch', 'actor' => $supervisor->id,
                'ship' => $target->id, 'outerSnapshot' => $outerSnapshot, 'expected_quantity' => $quantity,
                'data' => $command($target, ['confirmed' => true, 'note' => 'A rakomány átadva.']),
            ];
            $first = $dispatch($plan, $reorder ? 65 : $mainQuantity);
            $planChange = static fn (string $operation, array $payload, array $target = []): array => $target + [
                'action' => 'shipment_plan', 'operation' => $operation, 'actor' => $secondSupervisor->id,
                'ship' => $plan->id, 'outerSnapshot' => $outerSnapshot, 'data' => $command($plan, $payload),
            ];
            $resize = $command($truck, ['payload_capacity_kg' => 600]);
            $second = match ($scenario) {
                'same dispatch replay' => $first,
                'double dispatch' => $planChange('dispatch', ['confirmed' => true]) + ['expected_quantity' => 65],
                'detach' => $planChange('detach', ['reason' => 'Másik fuvar szükséges.'], ['intent' => $intent->id]),
                'reorder' => $planChange('reorder', ['stop_ids' => $reversedStops]),
                'change vehicle' => $planChange('select_vehicle', ['vehicle_id' => $replacement->id]),
                'edit cargo' => $planChange('save_cargo_item', ['source' => 'manual', 'confirmed' => true, 'gross_weight_grams' => 600000],
                    ['assignment' => $assignment->id]),
                'resize vehicle' => ['action' => 'vehicle_update', 'id' => $truck->id, 'actor' => $dispatchAdmin->id,
                    'outerSnapshot' => $outerSnapshot, 'data' => $resize],
                'issue free quantity', 'issue protected quantity' => ['action' => 'movement', 'outerSnapshot' => $outerSnapshot,
                    'data' => ['product_id' => $product->getKey(), 'warehouse_id' => $intentSource->getKey(), 'user_id' => $user->id,
                        'type' => 'out', 'quantity' => $scenario === 'issue free quantity' ? 15 : 16]],
                'finish picking' => ['action' => 'pick_task', 'actor' => $secondSupervisor->id, 'id' => $intent->id,
                    'outerSnapshot' => $outerSnapshot, 'data' => $command($intent, [
                        'quantity' => 1, 'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => 1]],
                        'unassigned_confirmation' => ['found' => true, 'description' => 'Kiadási gyűjtőhely.'],
                    ])],
                'two plans for one stock' => $dispatch($otherPlan, 35),
                'latest vehicle capacity' => null,
            };
            $intentIds = array_map(fn (TransferIntent $row) => $row->id, $intents);
            $taskIds = DB::table('warehouse_transfer_tasks')->whereIn('transfer_intent_id', $intentIds)->pluck('id');
            $history = static fn (): array => DB::table('warehouse_transfer_allocations')
                ->whereIn('warehouse_transfer_task_id', $taskIds)->orderBy('id')->get()->toArray();
            $beforeBalance = $availability->snapshot($intentSource->getKey(), $product->getKey());
            check($beforeBalance['consistent'] && $beforeBalance['free_quantity'] === 15 && $beforeBalance['physical_quantity'] === 80
                && $beforeBalance['staged_quantity'] === ($partial ? 64 : 65), $scenario.': incomplete stock fixture.');
            $beforeReadiness = $readiness->assess($plan, $plan->intentAssignments()->active()->with(['intent', 'stop'])->get());
            check($beforeReadiness['can_dispatch'] === !$partial, $scenario.': incorrect departure readiness before the barrier.');
            $beforeAudit = ShipmentPlanOperation::max('id');
            $beforeHistory = $history();
            $beforeVersions = $plan->version + ($otherPlan?->version ?? 0);
            $forceResize = $scenario === 'latest vehicle capacity';
            $vehiclesRace = $forceResize || $scenario === 'resize vehicle';
            $samePlanRace = in_array($scenario, ['double dispatch', 'same dispatch replay', 'detach', 'reorder', 'change vehicle', 'edit cargo'], true);
            $barrier = $vehiclesRace ? $truck : ($partial ? $intent : ($samePlanRace ? $plan : $product));
            $outcomes = race($database, [], $second ? [$first, $second] : [$first], recordBarrier: $barrier,
                beforeRelease: $forceResize ? fn () => app(VehicleRegistry::class)->save($dispatchAdmin, $resize, $truck) : null);

            $plan->refresh();
            $otherPlan?->refresh();
            $dispatches = ShipmentDispatch::whereIn('shipment_id', $planIds)->get();
            $transit = ShipmentTransitItem::whereIn('shipment_id', $planIds)->get()->keyBy('transfer_intent_id');
            $winnerDispatch = $dispatches->isNotEmpty();
            $expectedOutcomes = match ($scenario) {
                'same dispatch replay', 'issue free quantity', 'two plans for one stock' => ['OK', 'OK'],
                'latest vehicle capacity' => ['REJECTED'],
                'resize vehicle' => $winnerDispatch ? ['OK', 'OK'] : ['OK', 'REJECTED'],
                default => ['OK', 'REJECTED'],
            };
            check($outcomes === $expectedOutcomes, $scenario.': unexpected concurrent outcome '.implode(', ', $outcomes));
            check($dispatches->count() === ($twoPlans ? 2 : ($winnerDispatch ? 1 : 0))
                && (!$twoPlans || $otherPlan->status === 'in_transit')
                && $plan->status === ($winnerDispatch ? 'in_transit' : 'draft'), $scenario.': dispatch multiplicity or status is wrong.');
            if (in_array($scenario, ['double dispatch', 'same dispatch replay', 'issue free quantity', 'issue protected quantity'], true)) {
                check($winnerDispatch, $scenario.': no dispatch was performed.');
            }
            if ($partial || $forceResize) check(!$winnerDispatch, $scenario.': changed prerequisites allowed dispatch.');
            $expectedAudit = $twoPlans ? 2 : (($partial || $forceResize || ($vehiclesRace && !$winnerDispatch)) ? 0 : 1);
            $events = ShipmentPlanOperation::whereIn('shipment_id', $planIds)->where('id', '>', $beforeAudit)->get();
            check($events->count() === $expectedAudit && $plan->version + ($otherPlan?->version ?? 0) === $beforeVersions + $expectedAudit,
                $scenario.': a concurrent operation incorrectly wrote the version or audit.');
            check($events->where('action', 'dispatch')->count() === $dispatches->count(), $scenario.': missing or duplicate dispatch audit.');
            if ($samePlanRace && !$winnerDispatch) {
                check($events->sole()->action === $second['operation'], $scenario.': neither mutually exclusive action won.');
            }
            if ($vehiclesRace) {
                check($truck->refresh()->version === 2 && $truck->payload_capacity_grams === 600000, $scenario.': the vehicle capacity change was lost.');
                if ($winnerDispatch) {
                    $snapshot = $dispatches->sole()->snapshot;
                    check($snapshot['vehicle']['payload_capacity_grams'] === 1200000 && $snapshot['vehicle']['version'] === 1,
                        $scenario.': dispatch did not preserve the locked vehicle state it verified.');
                }
            }

            $moved = $winnerDispatch ? 65 : 0;
            $otherIssue = $scenario === 'issue free quantity' ? 15 : 0;
            $balance = $availability->snapshot($intentSource->getKey(), $product->getKey());
            check($balance['consistent'] && $sourceStock->refresh()->quantity === 80 - $moved - $otherIssue
                && $balance['staged_quantity'] === 65 - $moved && $balance['free_quantity'] === 15 - $otherIssue
                && $balance['source_reserved_quantity'] === 0, $scenario.': concurrent dispatch corrupted physical, free, staged or held stock.');
            check($transit->count() === ($winnerDispatch ? count($intents) : 0)
                && (int) $transit->sum('remaining_quantity') === $moved, $scenario.': duplicate or missing transit stock.');
            foreach ($intents as $lineIntent) {
                $task = $lineIntent->pickingTasks()->sole();
                $reservation = $lineIntent->stockReservations()->sole();
                check(($task->dispatched_at !== null) === $winnerDispatch && $task->status === 'picked'
                    && $task->staged_quantity === $lineIntent->quantity && $reservation->status === 'consumed'
                    && $reservation->remaining_quantity === 0, $scenario.': the picking or reservation history was lost.');
                if (!$winnerDispatch) continue;
                $item = $transit->get($lineIntent->id);
                check($item && (int) $item->dispatched_quantity === $lineIntent->quantity && (int) $item->remaining_quantity === $lineIntent->quantity
                    && (int) $item->warehouse_transfer_task_id === $task->id, $scenario.': the transit item was not linked to its original picking task.');
            }
            $ledger = InventoryMovement::where('product_id', $product->getKey())->orderBy('id')->get();
            check($ledger->count() === $transit->count() + (int) ($otherIssue > 0)
                && (int) $ledger->sum('quantity') === $moved + $otherIssue
                && $ledger->whereNotNull('shipment_transit_item_id')->pluck('shipment_transit_item_id')->sort()->values()->all() === $transit->pluck('id')->sort()->values()->all(),
                $scenario.': the existing inventory ledger does not match transit and ordinary issue.');
            $running = 80;
            foreach ($ledger as $movement) {
                check($movement->type === 'out' && $movement->stock_before === $running && $movement->stock_after === $running - $movement->quantity,
                    $scenario.': concurrent movements lost the chronological ledger balance.');
                $running = $movement->stock_after;
            }
            check($destinationStock->refresh()->quantity === 7 && $secondDestinationStock->refresh()->quantity === 9
                && InventoryTransfer::where('product_id', $product->getKey())->doesntExist(), $scenario.': dispatch changed destination or created a legacy shipment transfer.');
            $afterHistory = $history();
            check($partial ? count($afterHistory) === count($beforeHistory) + 1 && array_slice($afterHistory, 0, -1) == $beforeHistory : $afterHistory == $beforeHistory,
                $scenario.': dispatch altered the historical source allocations.');
            $dispatchRaceCount++;
            echo 'PASS: shipment dispatch '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }
    $vehicleRaceCount = 0;
    $secondVehicleAdmin = User::factory()->create(['role' => 'admin']);
    $vehicleRegistry = app(VehicleRegistry::class);
    $dispatchState = app(ShipmentDispatchService::class);
    $reason = 'Szervizben';
    $vehicleChange = static fn (Vehicle $truck, User $admin, bool $outerSnapshot, array $data): array => [
        'action' => 'vehicle_update', 'id' => $truck->id, 'actor' => $admin->id,
        'outerSnapshot' => $outerSnapshot, 'data' => $command($truck, $data),
    ];
    $checkInactivation = static function (Vehicle $truck, array $job) use ($reason): void {
        $event = $truck->operations()->sole();
        check($truck->version === 2 && !$truck->active && $event->actor_id === $job['actor']
            && $event->operation_key === $job['data']['operation_key'] && $event->occurred_at !== null,
            'The inactivation was lost or its writer was not audited.');
        $change = $event->result['status_change'] ?? [];
        check(($change['previous_active'] ?? null) === true && ($change['active'] ?? null) === false && ($change['reason'] ?? null) === $reason,
            'The inactivation audit did not retain the transition and reason.');
    };

    foreach ([false, true] as $outerSnapshot) {
        $snapshotLabel = $outerSnapshot ? ' with outer snapshots' : '';
        $truck = Vehicle::create(['name' => 'Concurrent registry edits']);
        $inactivation = $vehicleChange($truck, $dispatchAdmin, $outerSnapshot, ['active' => false, 'inactivation_reason' => $reason]);
        $rename = $vehicleChange($truck, $secondVehicleAdmin, $outerSnapshot, ['name' => 'Winning admin edit']);
        $outcomes = race($database, [], [$inactivation, $rename], recordBarrier: $truck);
        check($outcomes === ['OK', 'REJECTED'] && $truck->refresh()->version === 2,
            'Both admins modified the same version of the vehicle.');
        if ($truck->active) {
            $event = $truck->operations()->sole();
            check($truck->name === $rename['data']['name'] && $event->actor_id === $rename['actor']
                && $event->operation_key === $rename['data']['operation_key'], 'The registry audit did not identify the winning admin.');
        } else {
            check($truck->name === 'Concurrent registry edits', 'The stale rename overwrote the inactivated vehicle.');
            $checkInactivation($truck, $inactivation);
        }
        $vehicleRaceCount++;
        echo 'PASS: vehicle inactivation versus stale admin edit'.$snapshotLabel.PHP_EOL;

        foreach ([false, true] as $dispatchWins) {
            $scenario = $dispatchWins ? 'dispatch before inactivation' : 'inactivation before dispatch';
            $sku = 'VEHICLE-STATE-RACE-'.(int) $dispatchWins.'-'.(int) $outerSnapshot;
            $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $scenario, 'sku' => $sku, 'price' => 1]);
            $originStock = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 80]);
            $targetStock = Stock::create(['warehouse_id' => $intentDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 7]);
            $truck = Vehicle::create(['name' => $sku, 'payload_capacity_grams' => 1200000, 'pallet_slots_capacity' => 6]);
            $intent = $prepareDispatchIntent($product, $intentDestination, 65, 65);
            $plan = $prepareDispatchPlan($scenario, $truck, [$intent]);
            $version = $plan->version;
            $inactivation = $vehicleChange($truck, $dispatchAdmin, $outerSnapshot, ['active' => false, 'inactivation_reason' => $reason]);
            $dispatch = [
                'action' => 'shipment_plan', 'operation' => 'dispatch', 'actor' => $supervisor->id,
                'ship' => $plan->id, 'outerSnapshot' => $outerSnapshot, 'expected_quantity' => 65,
                'data' => $command($plan, ['confirmed' => true]),
            ];

            // The worker waits for the barrier while the parent performs the other real operation under the same row lock.
            $stateAtDispatch = null;
            $outcomes = race($database, [], [$dispatchWins ? $inactivation : $dispatch + ['expected_blocker' => 'vehicle_inactive']],
                recordBarrier: $truck, beforeRelease: function () use (
                    $dispatchWins, $planner, $supervisor, $plan, $dispatch, $dispatchState, $vehicleRegistry, $dispatchAdmin, $inactivation, $truck, &$stateAtDispatch,
                ): void {
                    if ($dispatchWins) {
                        $planner->dispatch($supervisor, $plan, $dispatch['data']);
                        $stateAtDispatch = $dispatchState->state($plan->refresh());
                    } else {
                        $vehicleRegistry->save($dispatchAdmin, $inactivation['data'], $truck);
                    }
                });

            $checkInactivation($truck->refresh(), $inactivation);
            $state = $dispatchState->state($plan->refresh());
            $balance = $availability->snapshot($intentSource->getKey(), $product->getKey());
            check($targetStock->refresh()->quantity === 7 && $balance['consistent'] && $balance['free_quantity'] === 15
                && $plan->vehicle_id === $truck->id, $scenario.': a vehicle state change altered the assignment, destination or free stock.');
            if ($dispatchWins) {
                check($outcomes === ['OK'] && $plan->version === $version + 1 && $state['shipment']['status'] === 'in_transit'
                    && $state['dispatch'] === $stateAtDispatch['dispatch'] && $state['transit_items'] === $stateAtDispatch['transit_items']
                    && $state['dispatch']['snapshot']['vehicle']['active'] === true && $state['dispatch']['snapshot']['vehicle']['version'] === 1
                    && $state['transit_quantity'] === 65 && $originStock->refresh()->quantity === 15 && $balance['staged_quantity'] === 0
                    && InventoryMovement::where('product_id', $product->getKey())->count() === 1,
                    $scenario.': inactivation changed dispatch history, transit or booked stock.');
            } else {
                check($outcomes === ['REJECTED'] && $plan->version === $version && $state['shipment']['status'] === 'draft'
                    && $state['dispatch'] === null && $state['transit_items'] === [] && $originStock->refresh()->quantity === 80
                    && $balance['staged_quantity'] === 65 && InventoryMovement::where('product_id', $product->getKey())->doesntExist(),
                    $scenario.': a dispatch accepted an inactive vehicle or partially booked stock.');
            }
            $vehicleRaceCount++;
            echo 'PASS: vehicle '.$scenario.$snapshotLabel.PHP_EOL;
        }
    }
    $receiptRaceCount = 0;
    $receivers = collect([User::factory()->create(['role' => 'worker']), User::factory()->create(['role' => 'worker'])]);
    foreach ([$intentDestination, $secondDispatchDestination] as $warehouse) {
        foreach ($receivers as $receiver) {
            $warehouse->memberships()->create(['user_id' => $receiver->id, 'role' => 'receiving_operator']);
        }
    }
    $receiptLocation = $intentDestination->storageLocations()->create([
        'code' => 'RECEIPT-RACE', 'name' => 'Receipt concurrency shelf', 'kind' => 'shelf',
        'max_weight_grams' => 100000, 'active' => true,
    ]);
    $receiptFixture = static function (string $sku, bool $secondStop = false) use (
        $category, $intentSource, $intentDestination, $secondDispatchDestination,
        $prepareDispatchIntent, $prepareDispatchPlan, $planner, $supervisor, $command,
    ): array {
        $product = Product::create(['category_id' => $category->getKey(), 'product_name' => $sku, 'sku' => $sku, 'price' => 1]);
        $origin = Stock::create(['warehouse_id' => $intentSource->getKey(), 'product_id' => $product->getKey(), 'quantity' => 80]);
        $target = Stock::create(['warehouse_id' => $intentDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 7]);
        $other = Stock::create(['warehouse_id' => $secondDispatchDestination->getKey(), 'product_id' => $product->getKey(), 'quantity' => 9]);
        $truck = Vehicle::create(['name' => $sku, 'payload_capacity_grams' => 1200000, 'pallet_slots_capacity' => 6]);
        $intents = [$prepareDispatchIntent($product, $intentDestination, 20, 20)];
        if ($secondStop) $intents[] = $prepareDispatchIntent($product, $secondDispatchDestination, 10, 10);
        $plan = $prepareDispatchPlan($sku, $truck, $intents);
        $planner->dispatch($supervisor, $plan, $command($plan, ['confirmed' => true]));
        $transit = $plan->transitItems()->with('stop')->get()->keyBy('to_warehouse_id');

        return [
            $product, $plan->refresh(), $origin, $target, $other,
            $transit[$intentDestination->getKey()], $transit->get($secondDispatchDestination->getKey()),
        ];
    };
    $receiptJob = static fn (ShipmentTransitItem $line, User $receiver, int $quantity, bool $outerSnapshot): array => [
        'action' => 'shipment_2_receipt', 'actor' => $receiver->id, 'ship' => $line->shipment_id,
        'stop' => $line->shipment_stop_id, 'outerSnapshot' => $outerSnapshot,
        'data' => [
            'operation_key' => (string) Str::uuid(), 'version' => (int) $line->stop->receiving_version, 'confirmed' => true,
            'items' => [['transit_item_id' => $line->id, 'quantity' => $quantity, 'note' => 'Ellenőrzött darabszám.']],
        ],
    ];
    $scenarios = [
        'same transit item', 'same receipt replay', 'same receipt key with different notes', 'different destination stops',
        'ordinary issue uses older stock', 'manual placement uses older stock',
        'ordinary issue after receipt cannot consume protected stock', 'manual placement after receipt cannot consume protected stock',
    ];

    foreach ([false, true] as $outerSnapshot) {
        foreach ($scenarios as $index => $scenario) {
            $differentStops = $scenario === 'different destination stops';
            $isPartial = $scenario === 'same transit item';
            $isIssue = str_starts_with($scenario, 'ordinary issue');
            $isPlacement = str_starts_with($scenario, 'manual placement');
            $protect = str_contains($scenario, 'cannot consume');
            [$product, $plan, $origin, $target, $other, $line, $secondLine] = $receiptFixture(
                'RECEIPT-RACE-'.$index.'-'.(int) $outerSnapshot, $differentStops,
            );
            $version = $plan->version;
            $originalSnapshot = $plan->dispatch()->sole()->snapshot;
            $first = $receiptJob($line, $receivers[0], $isPartial ? 15 : 20, $outerSnapshot);
            $second = match ($scenario) {
                'same transit item' => $receiptJob($line, $receivers[1], 15, $outerSnapshot),
                'same receipt replay' => $first,
                'same receipt key with different notes' => array_replace($first, [
                    'data' => $first['data'] + ['note' => 'Eltérő átvételi megjegyzés.'],
                ]),
                'different destination stops' => $receiptJob($secondLine, $receivers[1], 10, $outerSnapshot),
                default => $isIssue
                    ? ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'product_id' => $product->getKey(), 'warehouse_id' => $target->warehouse_id, 'user_id' => $user->id,
                        'type' => 'out', 'quantity' => $protect ? 8 : 7,
                    ]]
                    : ['action' => 'place', 'id' => $receiptLocation->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'product_id' => $product->getKey(), 'quantity' => $protect ? 8 : 7, 'cargo_weight_kg' => 1,
                    ]],
            };
            // Wait with the stock writer's old snapshot open, then commit a real receipt before it obtains the product lock.
            $jobs = $protect ? [$second] : [$first, $second];
            $outcomes = race($database, [], $jobs, recordBarrier: $isIssue || $isPlacement ? $product : $plan,
                beforeRelease: $protect ? fn () => runReceiptOperation($first) : null);
            $expected = match (true) {
                $protect => ['REJECTED'],
                $isPartial || $scenario === 'same receipt key with different notes' => ['OK', 'REJECTED'],
                default => ['OK', 'OK'],
            };
            check($outcomes === $expected, $scenario.': unexpected concurrent outcome '.implode(', ', $outcomes));

            $plan->refresh();
            $line->refresh();
            $booked = $isPartial ? 15 : 20;
            $issued = $isIssue && !$protect ? 7 : 0;
            $placed = $isPlacement && !$protect ? 7 : 0;
            check($origin->refresh()->quantity === 80 - ($differentStops ? 30 : 20)
                && $target->refresh()->quantity === 7 + $booked - $issued
                && $other->refresh()->quantity === ($differentStops ? 19 : 9), $scenario.': physical stock was booked incorrectly.');
            $stock = $availability->snapshot($target->warehouse_id, $product->getKey());
            check($stock['consistent'] && $stock['direct_issue_quantity'] === 7 - $issued - $placed
                && (int) $receiptLocation->placements()->where('stock_id', $target->id)->sum('quantity') === $placed,
                $scenario.': receipt stock was exposed to another stock write or placement.');
            $stop = $line->stop()->sole();
            check($line->remaining_quantity === 20 - $booked && $line->status === ($isPartial ? 'in_transit' : 'received')
                && ($stop->received_at !== null) === !$isPartial
                && (int) $stop->receiving_version === $first['data']['version'] + 1,
                $scenario.': receipt left an inconsistent transit, stop or receiving version.');
            if ($differentStops) {
                $secondStop = $secondLine->stop()->sole();
                check($secondLine->refresh()->remaining_quantity === 0 && $secondLine->status === 'received'
                    && $secondStop->received_at !== null && (int) $secondStop->receiving_version === $second['data']['version'] + 1,
                    $scenario.': the other stop was skipped or used the first stop’s version.');
            }
            check($plan->status === ($isPartial ? 'in_transit' : 'completed') && ($plan->finished_at !== null) === !$isPartial
                && $plan->version === $version + ($differentStops ? 2 : 1)
                && $plan->dispatch()->sole()->snapshot === $originalSnapshot, $scenario.': shipment completion, version or historical dispatch is wrong.');

            $receipts = DB::table('shipment_receipts')->where('shipment_id', $plan->id)->get();
            $items = DB::table('shipment_receipt_items')->where('product_id', $product->getKey())->get();
            $movements = InventoryMovement::where('product_id', $product->getKey())->get();
            check($receipts->count() === ($differentStops ? 2 : 1) && $items->count() === $receipts->count()
                && $movements->count() === $items->count() * 2 + (int) ($issued > 0),
                $scenario.': duplicate or missing receipt, receipt item or inventory movement.');
            foreach ($items as $item) {
                $receipt = $receipts->firstWhere('id', $item->shipment_receipt_id);
                $transit = (int) $item->shipment_transit_item_id === $line->id ? $line : $secondLine;
                $movement = $movements->where('shipment_receipt_item_id', $item->id)->sole();
                check($receipt && $transit && (int) $receipt->shipment_stop_id === (int) $transit->shipment_stop_id
                    && (int) $receipt->to_warehouse_id === (int) $transit->to_warehouse_id
                    && (int) $item->transfer_intent_id === (int) $transit->transfer_intent_id
                    && (int) $item->received_quantity === ($transit->id === $line->id ? $booked : 10)
                    && (int) $item->pending_putaway_quantity === (int) $item->received_quantity
                    && $movement->type === 'in' && (int) $movement->warehouse_id === (int) $transit->to_warehouse_id
                    && $movement->quantity === (int) $item->received_quantity
                    && $movement->stock_after - $movement->stock_before === $movement->quantity
                    && (int) $movement->user_id === (int) $receipt->actor_id,
                    $scenario.': the receipt audit is not linked to the correct load, item, warehouse and incoming movement.');
            }
            $receiptRaceCount++;
            echo 'PASS: shipment receipt '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }

    [$product, $plan, $origin, $target, , $line] = $receiptFixture('RECEIPT-ROLLBACK');
    $receipt = $receiptJob($line, $receivers[0], 20, false);
    $version = $plan->version;
    $originalSnapshot = $plan->dispatch()->sole()->snapshot;
    DB::unprepared("CREATE TRIGGER test_reject_receipt_movement BEFORE INSERT ON inventory_movements FOR EACH ROW
        BEGIN IF NEW.shipment_receipt_item_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced receipt movement failure';
        END IF; END");
    try {
        try {
            runReceiptOperation($receipt);
            check(false, 'The receipt did not reach the forced movement failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            check(str_contains($exception->getMessage(), 'Forced receipt movement failure'), 'Unexpected error in receipt rollback test.');
        }
        $stop = $line->stop()->sole();
        check($target->refresh()->quantity === 7 && $origin->refresh()->quantity === 60 && $line->refresh()->remaining_quantity === 20
            && $plan->refresh()->status === 'in_transit' && $plan->version === $version && $plan->finished_at === null
            && $plan->dispatch()->sole()->snapshot === $originalSnapshot && $stop->received_at === null
            && (int) $stop->receiving_version === $receipt['data']['version']
            && DB::table('shipment_receipts')->where('shipment_id', $plan->id)->doesntExist()
            && DB::table('shipment_receipt_items')->where('product_id', $product->getKey())->doesntExist()
            && InventoryMovement::where('product_id', $product->getKey())->count() === 1,
            'A late receipt movement failure left inventory, transit, status or audit changes.');
    } finally {
        DB::unprepared('DROP TRIGGER test_reject_receipt_movement');
    }
    runReceiptOperation($receipt);
    check($target->refresh()->quantity === 27 && $line->refresh()->remaining_quantity === 0,
        'A fully rolled back receipt could not be retried with its original key.');
    $receiptRaceCount++;
    echo 'PASS: shipment receipt rolls back after a late movement failure'.PHP_EOL;
    $putawayRaceCount = 0;
    $putawayFixture = static function (string $sku) use ($receiptFixture, $receiptJob, $receivers): array {
        [$product, , , $target, , $line] = $receiptFixture($sku);
        runReceiptOperation($receiptJob($line, $receivers[0], 20, false));

        return [$product, $target, ShipmentReceiptItem::where('shipment_transit_item_id', $line->id)->sole()];
    };
    $putawayLocation = static fn (string $code) => $intentDestination->storageLocations()->create([
        'code' => $code, 'name' => $code, 'kind' => 'shelf', 'max_weight_grams' => 30000, 'active' => true,
    ]);
    $putawayJob = static fn (ShipmentReceiptItem $item, User $actor, WarehouseLocation $location, int $quantity, bool $outerSnapshot): array => [
        'action' => 'shipment_receipt_putaway', 'id' => $item->id, 'actor' => $actor->id, 'outerSnapshot' => $outerSnapshot,
        'data' => [
            'operation_key' => (string) Str::uuid(), 'version' => (int) $item->putaway_version, 'confirmed' => true,
            'to_location_id' => $location->id, 'quantity' => $quantity, 'cargo_weight_kg' => 20,
            'pallet_slots' => 0, 'site_verified' => true,
        ],
    ];
    $scenarios = [
        'same receipt item' => ['OK', 'REJECTED'],
        'stale receipt version' => ['OK', 'REJECTED'],
        'same UUID replay' => ['OK', 'OK'],
        'same UUID different payload' => ['OK', 'REJECTED'],
        'different receipts share bin capacity' => ['OK', 'REJECTED'],
        'same UUID across different receipts' => ['OK', 'REJECTED'],
        'ordinary issue uses older stock' => ['OK', 'OK'],
        'manual placement uses older stock' => ['OK', 'OK'],
        'manual placement shares bin capacity' => ['OK', 'REJECTED'],
        'ordinary issue cannot consume receipt stock' => ['REJECTED'],
        'manual placement cannot consume receipt stock' => ['REJECTED'],
    ];

    foreach ([false, true] as $outerSnapshot) {
        foreach ($scenarios as $scenario => $expected) {
            $sku = 'PUTAWAY-RACE-'.$putawayRaceCount;
            [$product, $target, $item] = $putawayFixture($sku);
            $bin = $putawayLocation($sku);
            $otherBin = $putawayLocation($sku.'-OTHER');
            $otherReceipt = in_array($scenario, ['different receipts share bin capacity', 'same UUID across different receipts'], true)
                ? $putawayFixture($sku.'-SKU') : null;
            $manualCapacity = $scenario === 'manual placement shares bin capacity';
            $issue = str_starts_with($scenario, 'ordinary issue');
            $manual = str_starts_with($scenario, 'manual placement');
            $mustProtect = str_contains($scenario, 'cannot consume');
            $quantity = $scenario === 'stale receipt version' ? 8 : 15;
            $first = $putawayJob($item, $receivers[0], $bin, $quantity, $outerSnapshot);
            $second = match ($scenario) {
                'same receipt item', 'stale receipt version' => $putawayJob($item, $receivers[1], $otherBin, $quantity, $outerSnapshot),
                'same UUID replay' => $first,
                'same UUID different payload' => array_replace($first, [
                    'data' => array_replace($first['data'], ['to_location_id' => $otherBin->id]),
                ]),
                'different receipts share bin capacity' => $putawayJob($otherReceipt[2], $receivers[1], $bin, $quantity, $outerSnapshot),
                'same UUID across different receipts' => $putawayJob($otherReceipt[2], $receivers[1], $otherBin, $quantity, $outerSnapshot),
                default => $issue
                    ? ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'product_id' => $product->getKey(), 'warehouse_id' => $target->warehouse_id, 'user_id' => $user->id,
                        'type' => 'out', 'quantity' => $mustProtect ? 8 : 7,
                    ]]
                    : ['action' => 'place', 'id' => $manualCapacity ? $bin->id : $otherBin->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                        'product_id' => $product->getKey(), 'quantity' => $mustProtect ? 8 : 7,
                        'cargo_weight_kg' => $manualCapacity ? 20 : 1,
                    ]],
            };
            if ($scenario === 'same UUID across different receipts') $second['data']['operation_key'] = $first['data']['operation_key'];

            // The competing stock writer can start with an older snapshot even if putaway commits first.
            $outcomes = race($database, [], $mustProtect ? [$second] : [$first, $second],
                recordBarrier: $otherReceipt === null ? $product : $intentDestination,
                beforeRelease: $mustProtect ? fn () => runPutawayOperation($first) : null);
            check($outcomes === $expected, $scenario.': unexpected concurrent outcome '.implode(', ', $outcomes));

            $stocks = [[$product, $target, $item]];
            if ($otherReceipt !== null) $stocks[] = $otherReceipt;
            $audits = DB::table('shipment_receipt_putaways')->whereIn('shipment_receipt_item_id', collect($stocks)->pluck(2)->pluck('id'))->get();
            $manualPlaced = $manual && !$mustProtect && (!$manualCapacity || $audits->isEmpty()) ? 7 : 0;
            $issued = $issue && !$mustProtect ? 7 : 0;
            $placements = $bin->placements()->get()->concat($otherBin->placements()->get());
            check($audits->count() === ($manualCapacity && $manualPlaced > 0 ? 0 : 1)
                && $placements->count() === $audits->count() + (int) ($manualPlaced > 0)
                && $placements->where('warehouse_location_id', $bin->id)->sum('cargo_weight_grams') <= $bin->max_weight_grams,
                $scenario.': missing or duplicate placement evidence, or bin capacity was exceeded.');

            foreach ($stocks as [$stockProduct, $stock, $receiptItem]) {
                $audit = $audits->firstWhere('shipment_receipt_item_id', $receiptItem->id);
                $assigned = $audit ? $quantity : 0;
                $isOriginal = $stock->id === $target->id;
                $issuedForStock = $isOriginal ? $issued : 0;
                $manualForStock = $isOriginal ? $manualPlaced : 0;
                $snapshot = $availability->snapshot($stock->warehouse_id, $stockProduct->getKey());
                check($stock->refresh()->quantity === 27 - $issuedForStock
                    && InventoryMovement::where('product_id', $stockProduct->getKey())->count() === 2 + (int) ($issuedForStock > 0)
                    && $receiptItem->refresh()->received_quantity === 20
                    && $receiptItem->pending_putaway_quantity === 20 - $assigned
                    && (int) $receiptItem->putaway_version === $first['data']['version'] + (int) ($assigned > 0)
                    && $snapshot['consistent'] && $snapshot['pending_putaway_quantity'] === 20 - $assigned
                    && $snapshot['assigned_quantity'] === $assigned + $manualForStock
                    && $snapshot['direct_issue_quantity'] === 7 - $issuedForStock - $manualForStock,
                    $scenario.': physical, pending, assigned or ordinary issue quantities drifted.');

                if ($audit) {
                    $placement = $placements->firstWhere('id', (int) $audit->warehouse_placement_id);
                    check($placement && $placement->stock_id === $stock->id && $placement->quantity === $quantity
                        && $placement->warehouse_location_id === (int) $audit->warehouse_location_id
                        && $placement->cargo_weight_grams === 20000 && $placement->pallet_slots === 0,
                        $scenario.': audit does not identify the actual placement and load.');
                }
            }
            $putawayRaceCount++;
            echo 'PASS: shipment receipt putaway '.$scenario.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
        }
    }

    [$product, $stock, $item] = $putawayFixture('PUTAWAY-ROLLBACK');
    $bin = $putawayLocation('PUTAWAY-ROLLBACK');
    $job = $putawayJob($item, $receivers[0], $bin, 15, false);
    DB::unprepared("CREATE TRIGGER test_reject_putaway_audit BEFORE INSERT ON shipment_receipt_putaways FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced putaway audit failure'");
    try {
        try {
            runPutawayOperation($job);
            check(false, 'Putaway did not reach the forced audit failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            check(str_contains($exception->getMessage(), 'Forced putaway audit failure'), 'Unexpected error in putaway rollback test.');
        }
        check($stock->refresh()->quantity === 27 && InventoryMovement::where('product_id', $product->getKey())->count() === 2
            && $item->refresh()->pending_putaway_quantity === 20
            && (int) $item->putaway_version === $job['data']['version']
            && $bin->placements()->doesntExist()
            && DB::table('shipment_receipt_putaways')->where('operation_key', $job['data']['operation_key'])->doesntExist(),
            'An audit failure left stock, receipt item, placement or audit changes.');
    } finally {
        DB::unprepared('DROP TRIGGER test_reject_putaway_audit');
    }
    runPutawayOperation($job);
    check($item->refresh()->pending_putaway_quantity === 5 && $bin->placements()->sole()->quantity === 15,
        'A fully rolled back putaway could not be retried with its original key.');
    $putawayRaceCount++;
    echo 'PASS: shipment receipt putaway rolls back after a late audit failure'.PHP_EOL;

    $manualReceiptRaceCount = 0;
    $manualReceiver = User::factory()->create(['role' => 'admin']);
    foreach ([false, true] as $outerSnapshot) {
        $product = Product::factory()->create(['category_id' => $category->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $receive = ['action' => 'manual_receipt', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string) Str::uuid(),
            'data' => ['user_id' => $manualReceiver->id, 'product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 20]];
        check(race($database, [], [$receive, $receive], recordBarrier: $product) === ['OK', 'OK'], 'Manual receipt retry failed.');
        $batch = \App\Models\ManualReceiptBatch::where('product_id', $product->getKey())->sole();
        check($batch->pending_putaway_quantity === 20, 'Manual receipt protection was duplicated.');
        $bin = fn (string $code) => $warehouse->storageLocations()->create(['code' => $code, 'name' => $code,
            'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 15000]);
        $first = $bin('MANUAL-RACE-01');
        $second = $bin('MANUAL-RACE-02');
        $place = ['action' => 'manual_putaway', 'id' => $batch->id, 'actor' => $manualReceiver->id, 'outerSnapshot' => $outerSnapshot,
            'data' => ['operation_key' => (string) Str::uuid(), 'version' => 1, 'to_location_id' => $first->id,
                'quantity' => 15, 'cargo_weight_kg' => 1, 'pallet_slots' => 0, 'confirmed' => true, 'site_verified' => true]];
        $other = array_replace($place, ['data' => array_replace($place['data'], ['operation_key' => (string) Str::uuid(), 'to_location_id' => $second->id])]);
        check(race($database, [], [$place, $other], recordBarrier: $product) === ['OK', 'REJECTED'], 'Concurrent manual putaway reused held stock.');
        $winner = $batch->putaways()->sole();
        $replay = $winner->operation_key === $place['data']['operation_key'] ? $place : $other;
        check(race($database, [], [$replay, $replay], recordBarrier: $product) === ['OK', 'OK'], 'Manual putaway retry failed.');
        $balance = $availability->snapshot($warehouse->getKey(), $product->getKey());
        check($batch->fresh()->pending_putaway_quantity === 5 && $batch->putaways()->count() === 1
            && $balance['physical_quantity'] === 20 && $balance['assigned_quantity'] === 15 && $balance['pending_putaway_quantity'] === 5
            && InventoryMovement::where('product_id', $product->getKey())->count() === 1, 'Manual putaway balances drifted.');
        $manualReceiptRaceCount += 3;
        echo 'PASS: manual receipt and putaway replay and version conflict'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }

    $relocationRaceCount = 0;
    foreach ([false, true] as $outerSnapshot) {
        $warehouse = Warehouse::factory()->create();
        $newStock = function () use ($category, $warehouse) {
            $product = Product::factory()->create(['category_id' => $category->getKey()]);

            return Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => 20]);
        };
        $bin = function (string $code, int $capacity = 20000) use ($warehouse) {
            return $warehouse->storageLocations()->create(['code' => $code, 'name' => $code,
                'kind' => 'shelf', 'active' => true, 'max_weight_grams' => $capacity]);
        };
        $place = fn (Stock $stock, WarehouseLocation $location, int $grams) => $location->placements()->create([
            'stock_id' => $stock->id, 'quantity' => 20, 'cargo_weight_grams' => $grams, 'pallet_slots' => 0,
        ]);
        $command = fn (Stock $stock, WarehouseLocation $source, WarehouseLocation $target, int $quantity) => [
            'action' => 'relocate', 'actor' => $manualReceiver->id, 'outerSnapshot' => $outerSnapshot,
            'data' => ['warehouse_id' => $warehouse->getKey(), 'product_id' => $stock->product_id,
                'from_location_id' => $source->id, 'to_location_id' => $target->id, 'quantity' => $quantity,
                'confirmed' => true, 'site_verified' => true, 'operation_key' => (string) Str::uuid()],
        ];
        $stock = $newStock();
        $source = $bin('REK-FORRAS');
        $first = $bin('REK-CEL1');
        $second = $bin('REK-CEL2');
        $place($stock, $source, 10000);
        $jobs = [$command($stock, $source, $first, 15), $command($stock, $source, $second, 15)];
        foreach ($jobs as &$job) $job['data'] += ['cargo_weight_kg' => 7.5, 'pallet_slots' => 0];
        unset($job);
        check(race($database, $stock->product_id, $jobs) === ['OK', 'REJECTED'], 'Relocations used the same source stock twice.');
        $audit = \App\Models\WarehouseRelocation::where('warehouse_id', $warehouse->getKey())->sole();
        $remaining = $source->placements()->sole();
        check($audit->quantity === 15 && $remaining->quantity === 5 && $remaining->cargo_weight_grams === 10000
            && $remaining->load_requires_verification
            && (int) $first->placements()->sum('quantity') + (int) $second->placements()->sum('quantity') === 15
            && $stock->fresh()->quantity === 20 && InventoryMovement::where('product_id', $stock->product_id)->doesntExist(),
            'Competing relocations changed physical stock or lost the retained source load.');

        $another = $newStock();
        $retrySource = $bin('REK-UUID-FORRAS');
        $retryTarget = $bin('REK-UUID-CEL');
        $place($another, $retrySource, 6000);
        $duplicate = $command($another, $retrySource, $retryTarget, 20);
        check(race($database, $another->product_id, [$duplicate, $duplicate]) === ['OK', 'OK'], 'Same UUID did not replay a whole relocation.');
        check($retrySource->placements()->doesntExist() && $retryTarget->placements()->sole()->quantity === 20
            && \App\Models\WarehouseRelocation::where('operation_key', $duplicate['data']['operation_key'])->count() === 1,
            'Relocation idempotency duplicated a placement or its audit.');

        $left = $newStock();
        $right = $newStock();
        $leftBin = $bin('REK-KAPACITAS-1');
        $rightBin = $bin('REK-KAPACITAS-2');
        $shared = $bin('REK-KAPACITAS-CEL', 10000);
        $place($left, $leftBin, 8000);
        $place($right, $rightBin, 8000);
        $jobs = [$command($left, $leftBin, $shared, 20), $command($right, $rightBin, $shared, 20)];
        check(race($database, [], $jobs, recordBarrier: $warehouse) === ['OK', 'REJECTED'], 'Relocations exceeded shared target capacity.');
        check($shared->placements()->sole()->cargo_weight_grams === 8000
            && (int) $leftBin->placements()->sum('quantity') + (int) $rightBin->placements()->sum('quantity') === 20
            && $left->fresh()->quantity === 20 && $right->fresh()->quantity === 20,
            'Losing a target capacity race released source stock or booked a warehouse movement.');
        $relocationRaceCount += 3;
        echo 'PASS: warehouse relocation source, UUID and target capacity'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }

    $verificationRaceCount = 0;
    $verifier = User::factory()->create(['role' => 'admin']);
    $verifications = app(WarehouseLoadVerificationService::class);
    foreach ([false, true] as $outerSnapshot) {
        foreach (['relocation', 'issue', 'duplicate', 'late audit failure', 'reserve', 'putaway', 'confirm empty'] as $scenario) {
            $warehouse = Warehouse::factory()->create();
            $product = Product::factory()->create();
            $stock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => 10]);
            $source = $warehouse->storageLocations()->create(['code' => 'VERIFY-SOURCE', 'name' => 'Polc',
                'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 20000]);
            $placement = $source->placements()->create(['stock_id' => $stock->id, 'quantity' => 10, 'cargo_weight_grams' => 10000,
                'pallet_slots' => 0, 'load_requires_verification' => true]);
            $command = ['action' => 'verify_load', 'actor' => $verifier->id, 'warehouse' => $warehouse->getKey(),
                'id' => $placement->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                    'revision' => $verifications->show($verifier, $warehouse, $placement->id)['placement']['revision'],
                    'cargo_weight_kg' => 8, 'pallet_slots' => 0, 'operation_key' => (string) Str::uuid(),
                ]];
            $events = WarehouseLoadVerification::where('placement_id', $placement->id);

            if ($scenario === 'relocation') {
                $target = $warehouse->storageLocations()->create(['code' => 'VERIFY-TARGET', 'name' => 'Polc',
                    'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 20000]);
                $snapshot = $availability->snapshot($warehouse->getKey(), $product->getKey());
                $sourceState = app(WarehouseRelocationSources::class)->describe($source, $snapshot, collect([$placement]));
                $relocate = ['action' => 'relocate', 'actor' => $manualReceiver->id, 'outerSnapshot' => $outerSnapshot, 'data' => [
                    'warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(),
                    'from_location_id' => $source->id, 'to_location_id' => $target->id, 'quantity' => 4,
                    'cargo_weight_kg' => 4, 'pallet_slots' => 0, 'source_load_verified' => true,
                    'source_revision' => $sourceState['source_revision'], 'site_verified' => true, 'confirmed' => true,
                    'operation_key' => (string) Str::uuid(),
                ]];
                check(race($database, $product->getKey(), [$command, $relocate]) === ['OK', 'REJECTED'],
                    'A relocation and a verification confirmed the same outdated placement.');
                $verified = $events->exists();
                $placement->refresh();
                check([$placement->quantity, $placement->cargo_weight_grams, $placement->load_requires_verification]
                        === ($verified ? [10, 8000, false] : [6, 10000, true])
                    && (int) $target->placements()->sum('quantity') === ($verified ? 0 : 4)
                    && $events->count() === ($verified ? 1 : 0)
                    && $stock->fresh()->quantity === 10 && InventoryMovement::where('product_id', $product->getKey())->doesntExist(),
                    'Concurrent verification and relocation left inconsistent quantities, loads or audits.');
            }

            if ($scenario === 'issue') {
                $issue = ['action' => 'movement', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string) Str::uuid(), 'data' => [
                    'warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'user_id' => $manualReceiver->id,
                    'type' => 'out', 'quantity' => 4, 'source_allocations' => [
                        ['source_type' => 'location', 'location_id' => $source->id, 'quantity' => 4],
                    ],
                ]];
                $result = race($database, $product->getKey(), [$command, $issue]);
                $event = $events->first();
                $placement->refresh();
                check($result === ($event ? ['OK', 'OK'] : ['OK', 'REJECTED'])
                    && $placement->quantity === 6 && $placement->load_requires_verification
                    && $placement->cargo_weight_grams === ($event ? 8000 : 10000)
                    && (!$event || $event->quantity === 10)
                    && $stock->fresh()->quantity === 6
                    && InventoryMovement::where('product_id', $product->getKey())->sole()->quantity === 4,
                    'A measured pre-issue load incorrectly closed the post-issue placement.');
            }

            if ($scenario === 'duplicate') {
                check(race($database, $product->getKey(), [$command, $command]) === ['OK', 'OK'],
                    'A duplicate verification command did not replay.');
                check($events->sole()->operation_key === $command['data']['operation_key']
                    && $placement->fresh()->cargo_weight_grams === 8000 && !$placement->fresh()->load_requires_verification,
                    'A duplicate operation created an additional audit or changed the load twice.');
            }

            if ($scenario === 'late audit failure') {
                DB::unprepared("CREATE TRIGGER reject_load_audit_test BEFORE INSERT ON warehouse_load_verifications
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected load audit failure'");
                try {
                    $verifications->confirm($verifier, $warehouse, $placement->id, $command['data']);
                    throw new RuntimeException('The failing audit unexpectedly committed.');
                } catch (\Illuminate\Database\QueryException $error) {
                    check(str_contains($error->getMessage(), 'Injected load audit failure'), 'An unexpected error prevented the audit.');
                    $placement->refresh();
                    check($placement->quantity === 10 && $placement->cargo_weight_grams === 10000
                        && $placement->load_requires_verification && $events->doesntExist(),
                        'An audit failure did not roll back the measured load.');
                } finally {
                    DB::unprepared('DROP TRIGGER reject_load_audit_test');
                }
                check($verifications->confirm($verifier, $warehouse, $placement->id, $command['data'])->result === 'verified'
                    && $events->count() === 1, 'A rolled-back UUID could not be retried.');
            }
            if (in_array($scenario, ['reserve', 'putaway'], true)) {
                $incoming = Product::factory()->create();
                $origin = Warehouse::factory()->create();
                Stock::create(['warehouse_id' => $origin->getKey(), 'product_id' => $incoming->getKey(), 'quantity' => 3]);
                $source->update(['max_weight_grams' => 12000]);
                $command['data'] = array_replace($command['data'], ['cargo_weight_kg' => 14, 'measurement_confirmed' => true]);
                $putaway = null;
                if ($scenario === 'reserve') {
                    $other = ['action' => 'start', 'outerSnapshot' => $outerSnapshot, 'requestKey' => (string) Str::uuid(), 'data' => [
                        'from_warehouse_id' => $origin->getKey(), 'to_warehouse_id' => $warehouse->getKey(), 'product_id' => $incoming->getKey(),
                        'user_id' => $verifier->id, 'quantity' => 1, 'to_location_id' => $source->id, 'cargo_weight_kg' => 1,
                    ]];
                } else {
                    $receipt = app(InventoryService::class)->receiveManual([
                        'product_id' => $incoming->getKey(), 'warehouse_id' => $warehouse->getKey(), 'user_id' => $verifier->id, 'quantity' => 3,
                    ], (string) Str::uuid());
                    $putaway = $receipt->manualReceiptBatch;
                    $other = ['action' => 'manual_putaway', 'id' => $putaway->id, 'actor' => $verifier->id, 'outerSnapshot' => $outerSnapshot,
                        'data' => ['operation_key' => (string) Str::uuid(), 'version' => 1, 'to_location_id' => $source->id,
                            'quantity' => 1, 'cargo_weight_kg' => 1, 'pallet_slots' => 0, 'confirmed' => true, 'site_verified' => true]];
                }

                $outcomes = race($database, [], [$command, $other], recordBarrier: $warehouse);
                $committed = $outcomes === ['OK', 'OK'];
                check($committed || $outcomes === ['OK', 'REJECTED'], 'The concurrent load write returned unexpected results.');
                $audit = $events->sole();
                check($audit->result === 'rejected' && $audit->capacity_violations === ['location_weight']
                    && $audit->measured_weight_grams === 14000 && $placement->fresh()->cargo_weight_grams === 10000
                    && $placement->fresh()->load_requires_verification
                    && WarehouseLocation::withCapacityUsage()->findOrFail($source->id)->freeWeight() <= 0,
                    'The measured overload was lost or exposed false headroom.');
                if ($putaway) {
                    check($putaway->fresh()->pending_putaway_quantity === ($committed ? 2 : 3)
                        && $source->placements()->count() === ($committed ? 2 : 1), 'Blocked putaway did not preserve its staging balance.');
                } else {
                    check($source->reservations()->count() === ($committed ? 1 : 0), 'Blocked reservation changed destination capacity.');
                }
            }

            if ($scenario === 'confirm empty') {
                $source->update(['max_weight_grams' => 12000]);
                $input = array_replace($command['data'], ['cargo_weight_kg' => 14, 'measurement_confirmed' => true]);
                DB::unprepared("CREATE TRIGGER reject_open_load_audit_test BEFORE INSERT ON warehouse_load_verifications
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected discrepancy audit failure'");
                try {
                    $verifications->confirm($verifier, $warehouse, $placement->id, $input);
                    throw new RuntimeException('The failing discrepancy audit unexpectedly committed.');
                } catch (\Illuminate\Database\QueryException $error) {
                    check(str_contains($error->getMessage(), 'Injected discrepancy audit failure')
                        && $events->doesntExist() && $placement->fresh()->cargo_weight_grams === 10000
                        && WarehouseLocation::withCapacityUsage()->findOrFail($source->id)->freeWeight() === 2000,
                        'A rollback left a phantom discrepancy or changed the recorded load.');
                } finally {
                    DB::unprepared('DROP TRIGGER reject_open_load_audit_test');
                }
                $event = $verifications->confirm($verifier, $warehouse, $placement->id, $input);
                app(WarehouseCapacity::class)->release($source, $placement);
                check($verifications->show($verifier, $warehouse, $placement->id)['placement']['status'] === 'discrepancy_open',
                    'Removing the placement silently closed the discrepancy.');
                $empty = ['revision' => $verifications->show($verifier, $warehouse, $placement->id)['placement']['revision'],
                    'operation_key' => (string) Str::uuid(), 'cargo_weight_kg' => 0, 'pallet_slots' => 0, 'measurement_confirmed' => true];
                $closed = $verifications->confirm($verifier, $warehouse, $placement->id, $empty);
                check($closed->result === 'verified' && $closed->quantity === 0 && $closed->resolves_verification_id === $event->id
                    && WarehouseLocation::withCapacityUsage()->findOrFail($source->id)->freeWeight() === 12000
                    && $verifications->confirm($verifier, $warehouse, $placement->id, $empty)->id === $closed->id,
                    'The explicit empty observation did not close and replay correctly.');
            }

            $verificationRaceCount++;
        }
        echo 'PASS: load verification, relief, reservation and putaway races, UUID and rollback'.($outerSnapshot ? ' with outer snapshots' : '').PHP_EOL;
    }

    echo (69 + $intentRaceCount + $reservationRaceCount + $pickingRaceCount + $planningRaceCount + $cargoRaceCount
        + $dispatchRaceCount + $vehicleRaceCount + $receiptRaceCount + $putawayRaceCount + $manualIssueRaceCount + $manualReceiptRaceCount + $relocationRaceCount + $verificationRaceCount)." MySQL concurrency checks passed.\n";
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
