<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Shipment, Stock, User, Warehouse, WarehouseLocation, WarehousePlacement};
use App\Queries\ReplenishmentSuggestions;
use App\Services\InventoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $target;
    private Product $product;
    private User $worker;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = User::factory()->create(['role' => 'worker']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->source = Warehouse::create(['warehouse_name' => 'Induló raktár']);
        $this->target = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $category = Category::create(['category_name' => 'Tárhely teszt']);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'TAR-1', 'category_id' => $category->category_id, 'price' => 100]);
        $this->stock($this->source)->update(['quantity' => 300]);
        $this->actingAs($this->worker);
    }

    private function stock(Warehouse $warehouse): Stock
    {
        return Stock::firstOrCreate(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $this->product->product_id]);
    }

    private function location(array $data = [], ?Warehouse $warehouse = null): WarehouseLocation
    {
        return ($warehouse ?? $this->target)->storageLocations()->create($data + [
            'code' => 'A-01', 'name' => 'Raklapsor', 'kind' => 'pallet', 'max_weight_grams' => 100000, 'max_pallet_slots' => 80,
        ]);
    }

    private function transferData(array $data = []): array
    {
        return $data + [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->product_id, 'quantity' => 4,
            'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id,
        ];
    }

    public function test_location_configuration_is_scoped_versioned_and_reserved_for_managers(): void
    {
        $data = ['code' => 'a-01', 'name' => 'Alsó polc', 'kind' => 'shelf', 'max_weight_kg' => '12.345'];
        $url = route('warehouses.locations.store', $this->target);
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($this->manager)->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $location = WarehouseLocation::sole();
        $this->assertSame('A-01', $location->code);
        $this->assertSame(12345, $location->max_weight_grams);
        $this->assertNull($location->max_pallet_slots);
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post(route('warehouses.locations.store', $this->source), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('warehouses.locations.show', [$this->source, $location]))->assertNotFound();
        $this->patchJson(route('warehouses.locations.update', [$this->target, $location]), $data + ['active' => 1, 'lock_version' => 1])->assertRedirect();
        $this->patchJson(route('warehouses.locations.update', [$this->target, $location]), $data + ['active' => 1, 'lock_version' => 1])->assertStatus(409);
        $this->deleteJson(route('warehouses.destroy', $this->target))->assertUnprocessable();
        $this->actingAs($this->worker)->get(route('warehouses.locations.index', $this->target))->assertOk()->assertSee('Alsó polc');
    }

    public function test_transfer_reserves_capacity_once_and_receipt_turns_it_into_occupied_stock(): void
    {
        $location = $this->location();
        $data = $this->transferData(['quantity' => 200, 'to_location_id' => $location->id, 'cargo_weight_kg' => 100, 'pallet_slots' => 200]);
        $this->postJson(route('transfers.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('pallet_slots')
            ->assertJsonPath('errors.pallet_slots.0', 'A-01 tárhelyen 80 szabad raklaphely van. Hiányzik 120 hely.');
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertSame(300, $this->stock($this->source)->quantity);

        $data['pallet_slots'] = 80;
        $this->post(route('transfers.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('transfers.store'), array_replace($data, ['cargo_weight_kg' => '100.000']))->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('transfers.store'), array_replace($data, ['cargo_weight_kg' => '99']))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $transfer = InventoryTransfer::sole();
        $this->assertSame(100, $this->stock($this->source)->quantity);
        $this->assertDatabaseMissing('stocks', ['warehouse_id' => $this->target->warehouse_id, 'product_id' => $this->product->product_id]);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertSame(0, WarehouseLocation::withCapacityUsage()->findOrFail($location->id)->freeSlots());
        $this->get(route('warehouses.locations.show', [$this->target, $location]))->assertOk()->assertSee('80 lefoglalt');

        $this->patch(route('transfers.complete', $transfer))->assertRedirect()->assertSessionHasNoErrors();
        $this->patchJson(route('transfers.complete', $transfer))->assertUnprocessable();
        $this->assertSame(200, $this->stock($this->target)->quantity);
        $usage = WarehouseLocation::withCapacityUsage()->findOrFail($location->id);
        $this->assertSame(0, $usage->freeSlots());
        $this->assertSame(80, (int) $usage->occupied_slots);
        $this->assertSame(0, (int) $usage->reserved_slots);
        $this->assertSame(200, WarehousePlacement::sole()->quantity);
    }

    public function test_configured_destination_requires_an_active_local_location_and_exact_capacity(): void
    {
        $location = $this->location(['kind' => 'shelf', 'max_weight_grams' => 1000, 'max_pallet_slots' => null]);
        $foreign = $this->location([], $this->source);
        $url = route('transfers.store');
        $data = $this->transferData();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->postJson($url, $data + ['to_location_id' => $foreign->id, 'cargo_weight_kg' => 1])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $data += ['to_location_id' => $location->id];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson($url, $data + ['cargo_weight_kg' => '1.001'])->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson($url, $data + ['cargo_weight_kg' => 1, 'pallet_slots' => 1])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $location->update(['active' => false]);
        $this->postJson($url, $data + ['cargo_weight_kg' => 1])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(300, $this->stock($this->source)->quantity);
    }

    public function test_cancellation_releases_capacity_and_a_busy_location_cannot_be_downgraded(): void
    {
        $location = $this->location(['max_pallet_slots' => 1]);
        $data = $this->transferData(['to_location_id' => $location->id, 'cargo_weight_kg' => 100, 'pallet_slots' => 1]);
        $this->post(route('transfers.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('transfers.store'), array_replace($data, ['request_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['cargo_weight_kg', 'pallet_slots']);
        $edit = ['code' => 'A-01', 'name' => 'Raklapsor', 'kind' => 'pallet', 'max_weight_kg' => 100, 'max_pallet_slots' => 1, 'lock_version' => 1];
        $update = route('warehouses.locations.update', [$this->target, $location]);
        $this->actingAs($this->manager)->patchJson($update, $edit + ['active' => 0])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->patchJson($update, array_replace($edit, ['active' => 1, 'max_weight_kg' => 99]))->assertUnprocessable()->assertJsonValidationErrors('max_weight_kg');

        $this->actingAs($this->worker)->patch(route('transfers.cancel', InventoryTransfer::sole()))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(300, $this->stock($this->source)->quantity);
        $this->assertSame(1, WarehouseLocation::withCapacityUsage()->findOrFail($location->id)->freeSlots());
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->actingAs($this->manager)->patchJson($update, $edit + ['active' => 0])->assertRedirect();
        $this->assertFalse($location->fresh()->active);
    }

    public function test_stock_placement_preserves_the_ledger_and_outgoing_uses_only_unassigned_available_stock(): void
    {
        $location = $this->location([], $this->source);
        $stock = $this->stock($this->source);
        $stock->update(['quantity' => 10, 'quarantined_quantity' => 2]);
        $place = route('warehouses.locations.place', [$this->source, $location]);
        $data = ['request_key' => (string) Str::uuid(), 'product_id' => $this->product->product_id, 'quantity' => 8, 'cargo_weight_kg' => 75, 'pallet_slots' => 1];
        $this->postJson($place, array_replace($data, ['quantity' => 9]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->post($place, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($place, array_replace($data, ['cargo_weight_kg' => '75.000']))->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($place, array_replace($data, ['quantity' => 7]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->deleteJson(route('products.destroy', $this->product))->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertSame(10, $stock->fresh()->quantity);
        $this->assertSame(2, $stock->fresh()->quarantined_quantity);
        $this->postJson(route('transfers.store'), $this->transferData(['quantity' => 1]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('movements.store'), [
            'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id, 'quantity' => 1, 'type' => 'out',
            'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->getJson(route('movements.stock', ['product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id]))
            ->assertJsonPath('quantity', 8)->assertJsonPath('assigned_quantity', 8);

        $target = $this->stock($this->target);
        $target->update(['min_quantity' => 8]);
        $proposal = ReplenishmentSuggestions::query()->where('needs.id', $target->id);
        $this->assertNull((clone $proposal)->firstOrFail()->source_stock_id);
        $this->postJson(route('transfers.store'), $this->transferData(['quantity' => 1, 'replenishment_stock_id' => $target->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->delete(route('warehouses.locations.release', [$this->source, $location, WarehousePlacement::sole()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($place, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertSame($stock->id, (int) $proposal->firstOrFail()->source_stock_id);
        $this->post(route('transfers.store'), $this->transferData(['quantity' => 8]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $stock->fresh()->quantity);
    }

    public function test_shipment_checks_combined_reservations_atomically_and_receives_each_item_to_its_location(): void
    {
        $location = $this->location(['max_pallet_slots' => 2]);
        $other = Product::create(['product_name' => 'Anya', 'sku' => 'TAR-2', 'category_id' => $this->product->category_id, 'price' => 20]);
        $otherStock = Stock::create(['product_id' => $other->product_id, 'warehouse_id' => $this->source->warehouse_id, 'quantity' => 10]);
        $item = ['product_id' => $this->product->product_id, 'quantity' => 4, 'to_location_id' => $location->id, 'cargo_weight_kg' => 50, 'pallet_slots' => 1];
        $data = ['reference' => 'Raklapok', 'origin_warehouse_id' => $this->source->warehouse_id,
            'stops' => [['warehouse_id' => $this->target->warehouse_id, 'items' => [$item, array_replace($item, ['product_id' => $other->product_id, 'pallet_slots' => 2])]]]];
        $this->post(route('shipments.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $shipment = Shipment::sole();
        $this->assertSame(2, WarehouseLocation::withCapacityUsage()->findOrFail($location->id)->freeSlots());
        $this->postJson(route('shipments.start', $shipment), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(300, $this->stock($this->source)->quantity);
        $this->assertSame(10, $otherStock->fresh()->quantity);
        $this->assertSame('draft', $shipment->fresh()->status);

        $data['stops'][0]['items'][1]['pallet_slots'] = 1;
        $this->put(route('shipments.update', $shipment), $data + ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shipments.start', $shipment), ['version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $location->reservations()->count());
        $this->patch(route('shipments.receive', [$shipment, $shipment->stops()->sole()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $shipment->fresh()->status);
        $this->assertSame(0, $location->reservations()->count());
        $this->assertSame(2, $location->placements()->count());
        $this->assertSame(100000, (int) $location->placements()->sum('cargo_weight_grams'));
    }

    public function test_legacy_pending_transfer_can_still_be_received_after_locations_are_created(): void
    {
        $transfer = app(InventoryService::class)->startTransfer($this->transferData(['user_id' => $this->worker->id]));
        $location = $this->location();
        $this->get(route('warehouses.locations.index', $this->target))->assertOk()->assertSee('korábban indított átmozgatás');
        $this->patch(route('transfers.complete', $transfer))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4, $this->stock($this->target)->quantity);
        $this->assertSame(0, $location->placements()->count());
    }

    public function test_statistics_separate_active_capacity_reservations_and_physical_stock(): void
    {
        $pallet = $this->location(['max_pallet_slots' => 8]);
        $this->location(['code' => 'P-01', 'kind' => 'shelf', 'max_weight_grams' => 40000, 'max_pallet_slots' => null]);
        $this->location(['code' => 'X-01', 'active' => false]);
        $this->stock($this->target)->update(['quantity' => 12, 'quarantined_quantity' => 3]);
        $this->post(route('warehouses.locations.place', [$this->target, $pallet]), [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->product_id, 'quantity' => 2, 'cargo_weight_kg' => 25, 'pallet_slots' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $transfer = ['to_location_id' => $pallet->id, 'cargo_weight_kg' => 30, 'pallet_slots' => 2];
        $this->post(route('transfers.store'), $this->transferData($transfer))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('transfers.store'), $this->transferData($transfer))->assertRedirect()->assertSessionHasNoErrors();

        $url = route('warehouses.statistics');
        $this->get($url)->assertForbidden();
        $result = $this->actingAs($this->manager)->get($url)->assertOk()->viewData('rows');
        $source = $result->firstWhere('warehouse_id', $this->source->warehouse_id);
        $this->assertNull($source->max_weight);
        $this->assertSame(2, $source->outgoing_count);
        $selected = $this->get(route('warehouses.statistics', ['warehouse_id' => $this->target->warehouse_id]))->assertOk()->viewData('rows')->sole();
        foreach (['location_count' => 2, 'max_weight' => 140000, 'occupied_weight' => 25000, 'reserved_weight' => 60000,
            'max_slots' => 8, 'occupied_slots' => 1, 'reserved_slots' => 4, 'physical_units' => 12,
            'assigned_units' => 2, 'quarantined_units' => 3, 'incoming_count' => 2] as $key => $expected) {
            $this->assertSame($expected, (int) $selected->$key, $key);
        }
        $this->location(['kind' => 'shelf', 'max_weight_grams' => 60000, 'max_pallet_slots' => null], $this->source);
        $this->get($url)->assertOk()->assertSee('42,5%')->assertSee('200 kg')->assertSee('62,5%');
        $this->get($url.'?warehouse_id[]=1')->assertRedirect($url)->assertSessionHasErrors('warehouse_id');
    }

    public function test_statistics_pdf_uses_selected_warehouse_and_renders_only_enabled_sections(): void
    {
        $this->location(['max_weight_grams' => 80000]);
        $this->location(['max_weight_grams' => 900000], $this->source);
        $url = route('warehouses.statistics-pdf');
        $this->get($url)->assertForbidden();
        $this->actingAs($this->manager)->getJson($url.'?orientation=bad&show_weight[]=1&warehouse_id=999999')
            ->assertUnprocessable()->assertJsonValidationErrors(['orientation', 'show_weight', 'warehouse_id']);

        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertSame('pdf.warehouse-statistics', $view);
            $this->assertSame($this->target->warehouse_name, $data['scope']);
            $html = view($view, $data)->render();
            foreach (['Terhelési kapacitás', '80 kg', 'data:image/svg+xml;base64,'] as $included) {
                $this->assertStringContainsString($included, $html);
            }
            foreach (['900 kg', 'Raklapférőhely', 'Helyi készlet összetétele', 'Tárhelyek és átmozgatások', 'Aktív tárhelyek teherbírása'] as $excluded) {
                $this->assertStringNotContainsString($excluded, $html);
            }
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        Pdf::shouldReceive('stream')->once()->andReturn(response('ok'));
        $this->get(route('warehouses.statistics-pdf', ['warehouse_id' => $this->target->warehouse_id,
            'orientation' => 'landscape', 'preview' => 1, 'show_pallets' => 0, 'show_stock' => 0, 'show_counters' => 0, 'show_notes' => 0]))->assertOk();
    }

    public function test_invalid_transfer_form_values_can_be_corrected_after_redirect(): void
    {
        $create = route('transfers.create');
        $this->from($create)->post(route('transfers.store'), array_fill_keys([
            'product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'to_location_id', 'cargo_weight_kg', 'pallet_slots', 'replenishment_stock_id',
        ], ['invalid']) + ['request_key' => (string) Str::uuid()])->assertRedirect($create)->assertSessionHasErrors(['product_id', 'quantity', 'cargo_weight_kg']);
        $this->get($create)->assertOk()->assertSee('Új átmozgatás');
        $this->assertDatabaseCount('inventory_transfers', 0);
    }
}
