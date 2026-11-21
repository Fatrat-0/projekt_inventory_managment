<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, Product, Shipment, ShipmentReceiptItem, TransferIntent, User, Warehouse};
use App\Queries\StockListing;
use App\Services\{InventoryService, ShipmentPlanningService, ShipmentReceiving, SmartPicking, TransferIntentService, TransferStockReservationService, WarehouseCapacity, WarehouseStockAvailability, WarehouseTransferTaskService};
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentReceiptStockProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Warehouse $nextWarehouse;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@raktar.hu')->sole();
        $this->actingAs($this->admin);
        $plan = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        app(ShipmentPlanningService::class)->dispatch($this->admin, $plan, $this->command($plan->version, ['confirmed' => true]));

        $this->productId = Product::where('sku', 'DEMO-FUVAR-CSAVAR')->sole()->getKey();
        $transit = $plan->transitItems()->where('product_id', $this->productId)->sole();
        $stop = $plan->stops()->whereKey($transit->shipment_stop_id)->sole();
        $this->warehouse = Warehouse::findOrFail($stop->warehouse_id);
        $this->nextWarehouse = Warehouse::factory()->create();

        $this->movement('in', 50);
        app(ShipmentReceiving::class)->receive($this->admin, $plan, $stop, $this->command($stop->receiving_version, [
            'confirmed' => true,
            'items' => [['transit_item_id' => $transit->id, 'quantity' => 200]],
        ]));
    }

    public function test_manual_issue_legacy_transfer_and_manual_placement_only_use_ordinary_stock(): void
    {
        $this->assertBalance(250, 50, 50);
        $this->getJson(route('movements.stock', ['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->productId]))
            ->assertOk()->assertJsonPath('physical_quantity', 250)->assertJsonPath('pending_putaway_quantity', 200)
            ->assertJsonPath('quantity', 50)->assertJsonPath('direct_issue_quantity', 50);

        $inventory = app(InventoryService::class);
        $transfer = ['product_id' => $this->productId, 'from_warehouse_id' => $this->warehouse->getKey(),
            'to_warehouse_id' => $this->nextWarehouse->getKey(), 'quantity' => 51, 'user_id' => $this->admin->id];
        $this->rejectsQuantity(fn () => $this->movement('out', 51));
        $this->rejectsQuantity(fn () => $inventory->startTransfer($transfer));
        $this->rejectsQuantity(fn () => $inventory->startTransfer($transfer + [
            'source_allocations' => [['location_id' => null, 'quantity' => 51]],
        ]));

        $bin = $this->warehouse->storageLocations()->create([
            'code' => 'RECEIPT-STOCK-1', 'name' => 'Kézi elhelyezés próbapolca', 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 500000,
        ]);
        $placement = ['product_id' => $this->productId, 'quantity' => 51, 'cargo_weight_kg' => '1', 'user_id' => $this->admin->id];
        $capacity = app(WarehouseCapacity::class);
        $this->rejectsQuantity(fn () => $capacity->place($bin, $placement));
        $this->assertSame(0, $bin->placements()->count());

        $receiptMovement = InventoryMovement::where('warehouse_id', $this->warehouse->getKey())
            ->whereNotNull('shipment_receipt_item_id')->sole();
        $this->assertFalse($receiptMovement->isReturnable());
        $this->rejectsQuantity(fn () => $inventory->recordReturn($receiptMovement, [
            'quantity' => 1, 'return_reason' => 'Próba a védett átvételi mozgásra', 'user_id' => $this->admin->id,
        ]), 'movement');

        $capacity->place($bin, array_replace($placement, ['quantity' => 30]));
        $this->movement('out', 20);
        $this->assertBalance(230, 0, 30);
        $legacy = $inventory->startTransfer(array_replace($transfer, [
            'quantity' => 30, 'source_allocations' => [['location_id' => $bin->id, 'quantity' => 30]],
        ]));
        $this->assertSame('pending', $legacy->status);
        $this->assertSame(0, $bin->placements()->count());
        $this->assertBalance(200, 0, 0);
        $choices = $this->getJson(route('movements.out.options', ['warehouse_id' => $this->warehouse->getKey()]))->assertOk();
        $this->assertNotContains((string) $this->productId, array_column($choices->json('products'), 'id'));
        $this->rejectsQuantity(fn () => $this->movement('out', 1));

        $listing = new StockListing(Request::create('/stock', 'GET', [
            'product_id' => $this->productId, 'warehouse_id' => $this->warehouse->getKey(), 'stock_status' => 'out',
        ]));
        $this->assertSame(0, $listing->query()->sole()->available_quantity);
        $summary = $listing->summary();
        $this->assertSame(200, (int) $summary->physical);
        $this->assertSame(0, (int) $summary->available);

        $product = Product::findOrFail($this->productId);
        $expected = (int) $product->stocks()->sum('quantity') - (int) $product->stocks()->sum('quarantined_quantity') - 200;
        $this->get(route('products.show', $product))->assertOk()
            ->assertViewHas('product', fn (Product $shown) => (int) $shown->total_available === $expected);
    }

    public function test_manual_and_shipment_holds_are_counted_once_in_both_availability_projections(): void
    {
        $params = ['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->productId];
        $this->postJson(route('movements.store'), $params + [
            'type' => 'in', 'quantity' => 30, 'confirmed' => true, 'request_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertBalance(280, 50, 50, 230);
        $this->getJson(route('movements.stock', $params + ['details' => 1]))->assertOk()
            ->assertJsonPath('pending_putaway_quantity', 230)->assertJsonPath('pickable_quantity', 50);
        $this->getJson(route('movements.out.options', $params))->assertOk()
            ->assertJsonFragment(['id' => (string) $this->productId, 'pickable_quantity' => 50, 'direct_issue_quantity' => 50]);
    }

    public function test_protected_receipt_is_shown_separately_and_movement_origin_filters_use_links(): void
    {
        $params = ['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->productId];
        $this->getJson(route('movements.stock', $params + ['details' => 1]))->assertOk()
            ->assertJsonPath('physical_quantity', 250)->assertJsonPath('pending_putaway_quantity', 200)
            ->assertJsonPath('direct_issue_quantity', 50)->assertJsonPath('pickable_quantity', 50);
        $this->get(route('stock.index', $params))->assertOk()->assertSee('250 db')->assertSee('50 db')->assertSee('Betárolásra vár');

        $receipt = InventoryMovement::where($params)->whereNotNull('shipment_receipt_item_id')->sole();
        $dispatch = InventoryMovement::where('product_id', $this->productId)->whereNotNull('shipment_transit_item_id')->sole();
        $this->get(route('movements.index', $params + ['origin' => 'shipment_receipt', 'search' => $receipt->movement_number,
            'type' => 'in', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 10, 'page' => 1]))
            ->assertOk()->assertSee('Fuvarátvétel')->assertViewHas('movements', fn ($page) => $page->total() === 1 && $page->first()->is($receipt));
        $this->get(route('movements.index', $params + ['origin' => 'manual_in']))
            ->assertOk()->assertSee('Kézi bevételezés')->assertViewHas('movements', fn ($page) => $page->total() === 1 && !$page->first()->isShipmentReceipt());
        $this->get(route('movements.show', $receipt))->assertOk()->assertSee('Átvételi munkalap megnyitása');
        $this->get(route('movements.show', $dispatch))->assertOk()->assertSee('Fuvar megnyitása');
        $this->get(route('export.movements', $params + ['origin' => 'shipment_receipt', 'preview' => 1]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->get(route('movements.show', $receipt))->assertOk()->assertDontSee('Átvételi munkalap megnyitása');
        $this->get(route('movements.show', $dispatch))->assertOk()->assertDontSee('Fuvar megnyitása');
    }

    public function test_mixed_manual_issue_keeps_pending_receipt_protected_even_with_preflight(): void
    {
        $params = ['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->productId];
        $bin = $this->warehouse->storageLocations()->create(['code' => 'KIAD-01', 'name' => 'Próbapolc', 'kind' => 'shelf',
            'active' => true, 'max_weight_grams' => 500000]);
        app(WarehouseCapacity::class)->place($bin, ['product_id' => $this->productId, 'quantity' => 30,
            'cargo_weight_kg' => '1', 'user_id' => $this->admin->id]);
        $payload = $params + ['type' => 'out', 'request_key' => (string) Str::uuid(), 'quantity' => 51,
            'source_allocations' => [['source_type' => 'location', 'location_id' => $bin->id, 'quantity' => 30],
                ['source_type' => 'unassigned', 'quantity' => 21]]];
        $this->getJson(route('movements.out.sources', $params + ['quantity' => 51]))->assertOk()
            ->assertJsonPath('direct_issue_quantity', 20)->assertJsonPath('pending_putaway_quantity', 200)->assertJsonCount(0, 'suggestion');
        $this->postJson(route('movements.out.validate'), $payload)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('movements.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertBalance(250, 20, 50);
        $payload['quantity'] = 50;
        $payload['source_allocations'][1]['quantity'] = 20;
        $this->postJson(route('movements.store'), $payload)->assertOk()
            ->assertJsonPath('stock_before', 250)->assertJsonPath('stock_after', 200)->assertJsonCount(2, 'sources');
        $this->assertBalance(200, 0, 0);
    }

    public function test_source_reservation_picking_and_another_dispatch_keep_receipt_stock_protected(): void
    {
        $reservation = app(TransferStockReservationService::class);
        $picking = app(WarehouseTransferTaskService::class);
        $options = app(SmartPicking::class)->options($this->warehouse->getKey(), $this->productId, 51);
        $this->assertSame(50, $options['available_quantity']);
        $this->assertSame(200, $options['pending_putaway_quantity']);
        $this->assertSame(50, $options['sources'][0]['stored_quantity']);
        $this->assertSame([], $options['suggestion']);

        $tooLarge = $this->approvedIntent(51);
        $this->rejectsQuantity(fn () => $reservation->reserve($this->admin, $tooLarge, $this->command($tooLarge->version)));

        $legitimate = $this->approvedIntent(50);
        $reservation->reserve($this->admin, $legitimate, $this->command($legitimate->version));
        $picking->create($this->admin, $legitimate, $this->command($legitimate->refresh()->version));
        $pick = fn (int $quantity) => $picking->pick($this->admin, $legitimate, $this->command($legitimate->refresh()->version, [
            'quantity' => $quantity,
            'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $quantity]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A korábban meglévő tárhely nélküli készlet.'],
        ]));
        $this->rejectsQuantity(fn () => $pick(51));
        $pick(50);

        $service = app(ShipmentPlanningService::class);
        $plan = Shipment::findOrFail($service->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $this->warehouse->getKey(),
            'reference' => 'Korábbi szabad készlet feladása', 'intent_ids' => [$legitimate->id],
        ])['shipment']['id']);
        $vehicleId = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole()->vehicle_id;
        $service->selectVehicle($this->admin, $plan, $this->command($plan->version, ['vehicle_id' => $vehicleId]));
        $assignment = $plan->intentAssignments()->active()->sole();
        $cargo = $service->saveCargoItem($this->admin, $plan, $assignment->id, $this->command($plan->refresh()->version, [
            'confirmed' => true, 'source' => 'manual', 'gross_weight_grams' => 5000,
        ]));
        $service->confirmCargo($this->admin, $plan, $this->command($plan->refresh()->version, [
            'basis_revision' => $cargo['cargo']['basis_revision'], 'gross_weight_grams' => 5000, 'pallet_slots' => 0,
        ]));
        $service->dispatch($this->admin, $plan, $this->command($plan->refresh()->version, ['confirmed' => true]));

        $this->assertBalance(200, 0, 0);
        $this->assertSame(50, $plan->transitItems()->sole()->remaining_quantity);
        $this->assertSame(0, $this->nextWarehouse->stocks()->where('product_id', $this->productId)->count());
        $this->assertDatabaseCount('shipment_receipt_items', 1);
        $this->assertSame(200, ShipmentReceiptItem::sole()->pending_putaway_quantity);
    }

    private function approvedIntent(int $quantity): TransferIntent
    {
        $service = app(TransferIntentService::class);
        $created = $service->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->productId,
            'from_warehouse_id' => $this->warehouse->getKey(), 'to_warehouse_id' => $this->nextWarehouse->getKey(),
            'quantity' => $quantity, 'priority' => 'normal', 'shipping_preference' => 'groupable',
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $service->approve($this->admin, $intent, $this->command($intent->version));

        return $intent->refresh();
    }

    private function movement(string $type, int $quantity): InventoryMovement
    {
        return app(InventoryService::class)->recordMovement([
            'warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->productId,
            'user_id' => $this->admin->id, 'type' => $type, 'quantity' => $quantity,
        ]);
    }

    private function rejectsQuantity(Closure $action, string $field = 'quantity'): void
    {
        try {
            $action();
            $this->fail('A védett mennyiséget érintő műveletnek elutasítást kell adnia.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    private function assertBalance(int $physical, int $unassigned, int $free, int $pending = 200): void
    {
        $stock = app(WarehouseStockAvailability::class)->snapshot($this->warehouse->getKey(), $this->productId);
        $this->assertTrue($stock['consistent']);
        $this->assertSame($physical, $stock['physical_quantity']);
        $this->assertSame($pending, $stock['pending_putaway_quantity']);
        $this->assertSame($unassigned, $stock['unassigned_usable_quantity']);
        $this->assertSame($free, $stock['free_quantity']);
    }

    private function command(int $version, array $data = []): array
    {
        return ['operation_key' => (string) Str::uuid(), 'version' => $version] + $data;
    }
}
