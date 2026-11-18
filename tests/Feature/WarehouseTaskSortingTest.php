<?php

namespace Tests\Feature;

use App\Http\Controllers\WarehousePickingPageController;
use App\Models\{Category, Product, Shipment, ShipmentReceiptItem, ShipmentStop, Stock, TransferIntent, User, Warehouse, WarehouseTransferTask};
use App\Services\ShipmentReceivingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseTaskSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_picking_sort_columns_order_the_full_result_and_keep_filters_and_pagination(): void
    {
        $this->seed();
        $this->actingAs(User::where('email', 'admin@raktar.hu')->sole());
        $tasks = WarehouseTransferTask::with(['intent.product', 'intent.fromWarehouse', 'intent.toWarehouse'])->get();
        $statusOrder = ['pending' => 0, 'in_progress' => 1, 'picked' => 2, 'cancelled' => 3];
        $priorityOrder = ['normal' => 0, 'urgent' => 1, 'critical' => 2];
        foreach (array_keys(WarehousePickingPageController::SORTS) as $column) {
            $values = $tasks->mapWithKeys(fn ($task) => [$task->id => match ($column) {
                'reference' => $task->intent->reference,
                'product_name' => $task->intent->product->product_name,
                'from_warehouse' => $task->intent->fromWarehouse->warehouse_name,
                'to_warehouse' => $task->intent->toWarehouse->warehouse_name,
                'remaining_quantity' => $task->expected_quantity - $task->staged_quantity,
                'status' => $statusOrder[$task->status],
                'priority' => $priorityOrder[$task->intent->priority],
                default => $task->id,
            }])->all();
            foreach (['asc', 'desc'] as $direction) {
                $expected = $this->orderedIds($values, $direction);
                $this->get(route('warehouse-picking-pages.index', [
                    'status' => 'all', 'sort_by' => $column, 'sort_dir' => $direction, 'per_page' => 1, 'page' => 2,
                ]))->assertOk()->assertViewHas('tasks', fn ($page) => $page->total() === $tasks->count()
                    && $page->first()->id === $expected[1] && str_contains($page->url(3), 'sort_by='.$column));
            }
        }
        $this->get(route('warehouse-picking-pages.index', ['sort_by' => 'invalid', 'sort_dir' => 'invalid']))
            ->assertOk()->assertSee('Válassz érvényes rendezési oszlopot.')->assertSee('Válassz érvényes rendezési irányt.');

        $worker = User::factory()->create(['role' => 'worker']);
        $sourceId = $tasks->first()->warehouse_id;
        $worker->warehouseMemberships()->create(['warehouse_id' => $sourceId, 'role' => 'dispatch_operator']);
        $this->actingAs($worker)->get(route('warehouse-picking-pages.index', [
            'sort_by' => 'product_name', 'sort_dir' => 'asc', 'warehouse_id' => 999999,
        ]))->assertOk()->assertViewHas('tasks', fn ($page) => $page->total() === 0);
    }

    public function test_receiving_sort_uses_each_stops_own_totals_and_matches_json_with_stable_ties(): void
    {
        $plan = $this->dispatch();
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => 100]);
        $reader = app(ShipmentReceivingReader::class);
        $actor = auth()->user();
        $rows = $reader->openStops($actor, ['status' => 'all'])->getCollection();
        foreach (array_keys(ShipmentReceivingReader::RECEIVING_SORTS) as $column) {
            $values = $rows->mapWithKeys(fn ($row) => [$row['stop']['id'] => match ($column) {
                'reference' => $row['shipment']['reference'],
                'origin_warehouse' => $row['shipment']['origin_warehouse_name'],
                'warehouse_name' => $row['stop']['warehouse_name'],
                'item_count' => $row['stop']['item_count'],
                'remaining_quantity' => $row['stop']['remaining_quantity'],
                'status' => ['pending' => 0, 'partial' => 1, 'received' => 2][$row['stop']['receiving_status']],
                'dispatched_at' => $row['dispatch']['dispatched_at'],
                default => $row['shipment']['id'],
            }])->all();
            foreach (['asc', 'desc'] as $direction) {
                $expected = $column === 'shipment_id'
                    ? [$pecs->id, $gyor->id] : $this->orderedIds($values, $direction);
                $filters = ['sort_by' => $column, 'sort_dir' => $direction, 'status' => 'all'];
                $actual = $this->getJson(route('shipment-plans.receiving.index', $filters))->assertOk()->json('data');
                $this->assertSame($expected, array_column(array_column($actual, 'stop'), 'id'), $column.' '.$direction);
                $this->get(route('warehouse-receiving-pages.index', $filters + ['per_page' => 1, 'page' => 2]))->assertOk()
                    ->assertViewHas('stops', fn ($page) => $page->total() === 2 && $page->first()['stop']['id'] === $expected[1]);
            }
        }
        $this->getJson(route('shipment-plans.receiving.index', ['sort_by' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('sort_by');
        $this->get(route('warehouse-receiving-pages.index', ['sort_dir' => 'invalid']))->assertOk()->assertSee('Válassz érvényes rendezési irányt.');

        $worker = User::factory()->create(['role' => 'worker']);
        $worker->warehouseMemberships()->create(['warehouse_id' => $pecs->warehouse_id, 'role' => 'receiving_operator']);
        $this->actingAs($worker)->getJson(route('shipment-plans.receiving.index', ['sort_by' => 'remaining_quantity', 'sort_dir' => 'asc']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stop.id', $pecs->id);
    }

    public function test_putaway_numeric_and_status_sort_keeps_receipt_scope_and_actual_database_totals(): void
    {
        $plan = $this->dispatch();
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $firstReceipt = $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => 100]);
        $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => 100, 'DEMO-FUVAR-GEP' => 40]);
        $this->receive($plan, $gyor, ['DEMO-FUVAR-ALKATRESZ' => 24]);
        $itemId = $firstReceipt['items'][0]['id'];
        $item = ShipmentReceiptItem::findOrFail($itemId);
        $location = $item->warehouse->storageLocations()->where('code', 'FUV-A-01')->sole();
        $this->postJson(route('shipment-receipt-items.putaway.store', $item), [
            'operation_key' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'site_verified' => true,
            'quantity' => 80, 'to_location_id' => $location->id, 'cargo_weight_kg' => 5, 'pallet_slots' => 0,
        ])->assertCreated();
        $reader = app(ShipmentReceivingReader::class);
        $actor = auth()->user();
        $rows = $reader->pendingPutaway($actor, ['status' => 'all'])->getCollection();
        $before = $this->quantities();
        foreach (array_keys(ShipmentReceivingReader::PUTAWAY_SORTS) as $column) {
            $values = $rows->mapWithKeys(fn ($row) => [$row['id'] => $column === 'status'
                ? ['pending' => 0, 'partial' => 1, 'completed' => 2][$row['status']] : $row[$column]])->all();
            foreach (['asc', 'desc'] as $direction) {
                $expected = $this->orderedIds($values, $direction);
                $filters = ['sort_by' => $column, 'sort_dir' => $direction, 'status' => 'all'];
                $actual = $this->getJson(route('shipment-plans.receiving.pending-putaway', $filters))->assertOk()->json('data');
                $this->assertSame($expected, array_column($actual, 'id'), $column.' '.$direction);
                $this->get(route('warehouse-putaway-pages.index', $filters + ['per_page' => 1, 'page' => 2]))->assertOk()
                    ->assertViewHas('items', fn ($page) => $page->total() === 4 && $page->first()['id'] === $expected[1]);
            }
        }
        $this->getJson(route('shipment-plans.receiving.pending-putaway', [
            'sort_by' => 'pending_putaway_quantity', 'sort_dir' => 'desc', 'receipt_id' => $firstReceipt['receipt']['id'],
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $itemId)->assertJsonPath('data.0.pending_putaway_quantity', 20);
        $this->getJson(route('shipment-plans.receiving.pending-putaway', ['sort_dir' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('sort_dir');
        $this->assertSame($before, $this->quantities());
    }

    public function test_picking_secondary_sort_orders_numeric_ties_before_the_stable_id_and_preserves_scopes(): void
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin']));
        $source = Warehouse::create(['warehouse_name' => 'Rendezés forrás']);
        $destination = Warehouse::create(['warehouse_name' => 'Rendezés cél']);
        $category = Category::create(['category_name' => 'Rendezés kategória']);
        $product = Product::create(['category_id' => $category->getKey(), 'sku' => 'PICK-SORT', 'product_name' => 'Rendezett termék', 'price' => 1]);
        Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'quantity' => 1000]);
        $created = [];
        foreach ([['normal', 100], ['urgent', 10], ['critical', 2], ['urgent', 2], ['urgent', 2]] as [$priority, $quantity]) {
            $response = $this->postJson(route('transfer-intents.store'), [
                'operation_key' => (string) Str::uuid(), 'product_id' => $product->getKey(), 'quantity' => $quantity,
                'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $destination->getKey(),
                'priority' => $priority, 'reason' => 'Többszintű rendezés ellenőrzése.',
            ])->assertCreated()->json();
            $intent = TransferIntent::findOrFail($response['intent']['id']);
            $command = fn () => ['operation_key' => (string) Str::uuid(), 'version' => $intent->refresh()->version];
            $this->patchJson(route('transfer-intents.approve', $intent), $command())->assertOk();
            $this->postJson(route('transfer-intents.stock-reservation.store', $intent), $command())->assertCreated();
            $task = $this->postJson(route('transfer-intents.picking-task.store', $intent), $command())->assertCreated()->json('task');
            $created[] = $task['id'];
        }
        $before = $this->quantities();
        $filters = ['sort_by' => 'priority', 'sort_dir' => 'desc', 'then_by' => 'remaining_quantity', 'then_dir' => 'asc',
            'search' => 'PICK-SORT', 'warehouse_id' => $source->getKey(), 'status' => 'open', 'per_page' => 2];
        $expected = [$created[2], $created[4], $created[3], $created[1], $created[0]];
        foreach ([1, 2, 3] as $page) {
            $this->get(route('warehouse-picking-pages.index', $filters + ['page' => $page]))->assertOk()
                ->assertViewHas('tasks', function ($rows) use ($expected, $page) {
                    $this->assertSame(array_slice($expected, ($page - 1) * 2, 2), $rows->getCollection()->modelKeys());
                    parse_str(parse_url($rows->url(2), PHP_URL_QUERY), $query);
                    $this->assertSame('remaining_quantity', $query['then_by']);
                    $this->assertSame('asc', $query['then_dir']);
                    $this->assertSame('PICK-SORT', $query['search']);
                    return $rows->total() === 5;
                });
        }
        $this->get(route('warehouse-picking-pages.index', array_replace($filters, ['then_dir' => 'desc', 'per_page' => 100])))
            ->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->getCollection()->modelKeys()
                === [$created[2], $created[1], $created[4], $created[3], $created[0]]);
        $this->get(route('warehouse-picking-pages.index', array_replace($filters, ['sort_dir' => 'asc', 'per_page' => 100])))
            ->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->getCollection()->modelKeys()
                === [$created[0], $created[3], $created[4], $created[1], $created[2]]);
        $this->get(route('warehouse-picking-pages.index', array_replace($filters, ['then_by' => 'priority'])))
            ->assertOk()->assertViewHas('filters', fn ($valid) => !isset($valid['then_by']) && !isset($valid['then_dir']));
        $this->get(route('warehouse-picking-pages.index', array_replace($filters, ['then_by' => ['id'], 'then_dir' => 'DROP TABLE stocks'])))
            ->assertOk()->assertViewHas('filters', fn ($valid) => $valid['sort_by'] === 'priority' && !isset($valid['then_by']));
        $worker = User::factory()->create(['role' => 'worker']);
        $this->actingAs($worker)->get(route('warehouse-picking-pages.index', $filters))->assertForbidden();
        $this->assertSame($before, $this->quantities());
    }

    public function test_receiving_secondary_sort_overrides_route_position_only_within_primary_ties(): void
    {
        $plan = $this->dispatch();
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $before = $this->quantities();
        $filters = ['sort_by' => 'shipment_id', 'sort_dir' => 'desc', 'then_by' => 'remaining_quantity', 'then_dir' => 'asc',
            'search' => $plan->reference, 'status' => 'all', 'per_page' => 1];
        // The smaller Győr stop must come first, even though it is second on the route.
        foreach ([1 => $gyor->id, 2 => $pecs->id] as $page => $expected) {
            $this->getJson(route('shipment-plans.receiving.index', $filters + ['page' => $page]))->assertOk()
                ->assertJsonPath('data.0.stop.id', $expected)->assertJsonPath('total', 2);
            $this->get(route('warehouse-receiving-pages.index', $filters + ['page' => $page]))->assertOk()
                ->assertViewHas('stops', function ($rows) use ($expected) {
                    parse_str(parse_url($rows->url(2), PHP_URL_QUERY), $query);
                    $this->assertSame('remaining_quantity', $query['then_by']);
                    $this->assertSame('asc', $query['then_dir']);
                    return $rows->first()['stop']['id'] === $expected;
                });
        }
        $this->getJson(route('shipment-plans.receiving.index', array_replace($filters, ['then_dir' => 'desc'])))
            ->assertOk()->assertJsonPath('data.0.stop.id', $pecs->id);
        // Equal primary/secondary values fall back to the original route order and then ID.
        $this->getJson(route('shipment-plans.receiving.index', array_replace($filters, ['then_by' => 'dispatched_at'])))
            ->assertOk()->assertJsonPath('data.0.stop.id', $pecs->id);
        $this->getJson(route('shipment-plans.receiving.index', array_replace($filters, ['then_by' => 'shipment_id'])))
            ->assertOk()->assertJsonPath('data.0.stop.id', $pecs->id);
        $this->getJson(route('shipment-plans.receiving.index', array_replace($filters, ['then_by' => 'invalid'])))
            ->assertUnprocessable()->assertJsonValidationErrors('then_by');
        $this->getJson(route('shipment-plans.receiving.index', array_replace($filters, ['then_dir' => ['desc']])))
            ->assertUnprocessable()->assertJsonValidationErrors('then_dir');
        $worker = User::factory()->create(['role' => 'worker']);
        $worker->warehouseMemberships()->create(['warehouse_id' => $pecs->warehouse_id, 'role' => 'receiving_operator']);
        $this->actingAs($worker)->getJson(route('shipment-plans.receiving.index', $filters))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.stop.id', $pecs->id);
        $this->assertSame($before, $this->quantities());
    }

    public function test_putaway_secondary_quantity_and_date_sort_remains_stable_across_pages_and_read_only(): void
    {
        $plan = $this->dispatch();
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $ids = [];
        foreach ([100, 2, 10, 2] as $quantity) {
            $this->travel(1)->minutes();
            $receipt = $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => $quantity]);
            $ids[] = $receipt['items'][0]['id'];
        }
        $this->receive($plan, $gyor, ['DEMO-FUVAR-ALKATRESZ' => 2]);
        $before = $this->quantities();
        $filters = ['sort_by' => 'product_name', 'sort_dir' => 'asc', 'then_by' => 'received_quantity', 'then_dir' => 'asc',
            'warehouse_id' => $pecs->warehouse_id, 'search' => 'DEMO-FUVAR-CSAVAR', 'status' => 'all', 'per_page' => 2];
        $expected = [$ids[1], $ids[3], $ids[2], $ids[0]];
        foreach ([1, 2] as $page) {
            $this->getJson(route('shipment-plans.receiving.pending-putaway', $filters + ['page' => $page]))->assertOk()
                ->assertJsonPath('total', 4)->assertJsonPath('data.0.id', $expected[($page - 1) * 2])
                ->assertJsonPath('data.1.id', $expected[($page - 1) * 2 + 1]);
            $this->get(route('warehouse-putaway-pages.index', $filters + ['page' => $page]))->assertOk()
                ->assertViewHas('items', function ($rows) use ($expected, $page) {
                    parse_str(parse_url($rows->url(2), PHP_URL_QUERY), $query);
                    $this->assertSame('received_quantity', $query['then_by']);
                    $this->assertSame('asc', $query['then_dir']);
                    return $rows->getCollection()->pluck('id')->all() === array_slice($expected, ($page - 1) * 2, 2);
                });
        }
        $this->getJson(route('shipment-plans.receiving.pending-putaway', array_replace($filters, [
            'then_by' => 'received_at', 'then_dir' => 'desc', 'per_page' => 100,
        ])))->assertOk()->assertJsonPath('data.0.id', $ids[3])->assertJsonPath('data.3.id', $ids[0]);
        $this->getJson(route('shipment-plans.receiving.pending-putaway', array_replace($filters, [
            'then_dir' => 'desc', 'per_page' => 100,
        ])))->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.3.id', $ids[3]);
        $this->getJson(route('shipment-plans.receiving.pending-putaway', array_replace($filters, ['then_by' => 'product_name'])))
            ->assertOk()->assertJsonPath('total', 4);
        $this->get(route('warehouse-putaway-pages.index', array_replace($filters, ['then_by' => 'product_name'])))
            ->assertOk()->assertViewHas('filters', fn ($valid) => !isset($valid['then_by']) && !isset($valid['then_dir']));
        $this->getJson(route('shipment-plans.receiving.pending-putaway', array_replace($filters, ['then_by' => ['id']])))
            ->assertUnprocessable()->assertJsonValidationErrors('then_by');
        $worker = User::factory()->create(['role' => 'worker']);
        $worker->warehouseMemberships()->create(['warehouse_id' => $gyor->warehouse_id, 'role' => 'receiving_operator']);
        $this->actingAs($worker)->getJson(route('shipment-plans.receiving.pending-putaway', array_replace($filters, ['warehouse_id' => $gyor->warehouse_id])))
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($before, $this->quantities());
    }

    private function orderedIds(array $values, string $direction): array
    {
        $ids = array_keys($values);
        usort($ids, fn ($left, $right) => (($values[$left] <=> $values[$right]) ?: ($left <=> $right)) * ($direction === 'asc' ? 1 : -1));

        return $ids;
    }

    private function dispatch(): Shipment
    {
        $this->seed();
        $this->actingAs(User::where('email', 'admin@raktar.hu')->sole());
        $plan = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        $this->postJson(route('shipment-plans.dispatch.store', $plan), [
            'operation_key' => (string) Str::uuid(), 'version' => $plan->version, 'confirmed' => true,
        ])->assertOk();

        return $plan->refresh();
    }

    private function receive(Shipment $plan, ShipmentStop $stop, array $quantities): array
    {
        $items = $stop->transitItems()->with('product')->get()->flatMap(fn ($item) => isset($quantities[$item->product->sku])
            ? [['transit_item_id' => $item->id, 'quantity' => $quantities[$item->product->sku]]] : [])->all();

        return $this->postJson(route('shipment-plans.stops.receipts.store', [$plan, $stop]), [
            'operation_key' => (string) Str::uuid(), 'version' => $stop->fresh()->receiving_version, 'confirmed' => true, 'items' => $items,
        ])->assertCreated()->json();
    }

    private function quantities(): array
    {
        $snapshot = [];
        foreach (['stocks', 'inventory_movements', 'warehouse_placements', 'transfer_stock_reservations',
            'warehouse_transfer_tasks', 'warehouse_transfer_allocations', 'shipment_transit_items', 'shipment_receipt_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
