<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse, WarehouseLocation};
use App\Queries\WarehouseStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseStorageDesignerTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Állványteszt']);
        $this->actingAs(User::factory()->create(['role' => 'manager']));
    }

    private function rack(array $overrides = []): array
    {
        return $overrides + ['version' => 1, 'kind' => 'rack', 'name' => 'Szerelési állvány', 'zone' => 'A csarnok',
            'width' => 6, 'height' => 2, 'rack_height' => 2.25, 'x' => 0, 'y' => 0,
            'levels' => 3, 'bins' => 4, 'bin_weight' => 50, 'level_weight' => 120, 'system_weight' => 250];
    }

    public function test_rack_creation_generates_unique_bins_and_preserves_unplaced_locations_when_moved_and_edited(): void
    {
        $legacy = $this->warehouse->storageLocations()->create(['code' => 'T1-S01-H01', 'name' => 'Korábbi polc',
            'kind' => 'shelf', 'max_weight_grams' => 10000]);
        $url = route('warehouses.floor-plan.systems.store', $this->warehouse);
        $this->postJson($url, $this->rack(['bin_weight' => 0]))->assertUnprocessable()->assertJsonValidationErrors('bin_weight');
        $this->assertDatabaseCount('warehouse_storage_systems', 0);
        $id = $this->postJson($url, $this->rack())->assertCreated()->assertJsonPath('version', 2)->json('id');
        $system = $this->warehouse->storageSystems()->findOrFail($id);
        $this->assertSame('A csarnok', $system->zone->name);
        $this->assertSame(225, (int) $system->height_cm);
        $this->assertSame([4, 4, 4], $system->levels()->withCount('locations')->get()->pluck('locations_count')->all());
        $codes = $system->locations()->pluck('code');
        $this->assertCount(12, $codes->unique());
        $this->assertNotContains($legacy->code, $codes);

        $this->postJson($url, $this->rack(['version' => 2]))->assertUnprocessable()->assertJsonValidationErrors('positions');
        $this->assertDatabaseCount('warehouse_locations', 13);
        $move = ['columns' => 20, 'rows' => 14, 'version' => 2, 'positions' => [],
            'systems' => [['id' => $id, 'x' => 4, 'y' => 5, 'width' => 2, 'height' => 6]]];
        $this->putJson(route('warehouses.floor-plan.update', $this->warehouse), $move)->assertOk()->assertJsonPath('version', 3);

        $edit = route('warehouses.floor-plan.systems.update', [$this->warehouse, $id]);
        $this->patchJson($edit, $this->rack(['version' => 2]))->assertStatus(409);
        $this->patchJson($edit, $this->rack(['version' => 3, 'name' => 'Alkatrészállvány', 'rack_height' => 3]))->assertNoContent();
        $plan = $this->get(route('warehouses.floor-plan.show', $this->warehouse))->assertOk()->viewData('plan');
        $this->assertSame($legacy->id, $plan['locations']->sole()['id']);
        $this->assertNull($legacy->fresh()->floor_x);
        $rack = $plan['systems']->sole();
        $this->assertSame(['Alkatrészállvány', 4, 5, 2, 6], [$rack['name'], $rack['x'], $rack['y'], $rack['width'], $rack['height']]);
        $this->assertEquals(3, $rack['rackHeight']);
        $this->assertSame($codes->all(), $system->locations()->pluck('code')->all());
    }

    public function test_shared_capacity_applies_to_physical_stock_and_reserved_transfers_without_losing_either(): void
    {
        $source = Warehouse::create(['warehouse_name' => 'Küldő raktár']);
        $category = Category::create(['category_name' => 'Állványteszt']);
        $product = Product::create(['sku' => 'RACK-TEST', 'product_name' => 'Szerelvény', 'category_id' => $category->category_id, 'price' => 1]);
        foreach ([$this->warehouse, $source] as $warehouse) {
            Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 20]);
        }
        $id = $this->postJson(route('warehouses.floor-plan.systems.store', $this->warehouse), $this->rack())->assertCreated()->json('id');
        $bins = $this->warehouse->storageSystems()->findOrFail($id)->locations()->orderBy('code')->get();
        $place = fn (int $bin, int $kg) => $this->postJson(route('warehouses.locations.place', [$this->warehouse, $bins[$bin]]),
            ['request_key' => (string) Str::uuid(), 'product_id' => $product->product_id, 'quantity' => 1, 'cargo_weight_kg' => $kg]);
        $place(0, 45)->assertRedirect();
        $place(1, 45)->assertRedirect();
        $transferData = ['request_key' => (string) Str::uuid(), 'product_id' => $product->product_id, 'quantity' => 1,
            'from_warehouse_id' => $source->warehouse_id, 'to_warehouse_id' => $this->warehouse->warehouse_id,
            'to_location_id' => $bins[2]->id, 'cargo_weight_kg' => 30];
        $this->postJson(route('transfers.store'), $transferData)->assertRedirect();
        $place(3, 1)->assertUnprocessable()->assertJsonPath('errors.cargo_weight_kg.0', 'A tétel meghaladná az állványszint szabad teherbírását.');
        $place(4, 50)->assertRedirect();
        $place(5, 50)->assertRedirect();
        $place(8, 30)->assertRedirect();
        $place(9, 1)->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson(route('transfers.store'), array_replace($transferData, [
            'request_key' => (string) Str::uuid(), 'to_location_id' => $bins[9]->id, 'cargo_weight_kg' => 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');

        $plan = $this->get(route('warehouses.floor-plan.show', $this->warehouse))->assertOk()->viewData('plan');
        $rack = $plan['systems']->sole();
        $this->assertSame([220000, 30000, 0], [$rack['occupied'], $rack['reserved'], $rack['free']]);
        $this->assertSame(5, $rack['products']->sole()['quantity']);
        $this->assertSame(0, $rack['levels'][0]['bins'][3]['free']);
        $this->assertSame('Szerelvény', $rack['levels'][0]['bins'][0]['products']->sole()['name']);
        $summary = $this->get(route('warehouses.locations.index', $this->warehouse))->assertOk()
            ->assertSee('0 kg szabad teherbírás')->assertDontSee($bins[3]->code)->viewData('summaries')->get($id);
        $this->assertEquals(1, $summary['utilization']['weight']);
        $this->assertSame('full', $summary['tone']);
        $this->get(route('warehouses.locations.show', [$this->warehouse, $bins[3]]))->assertOk()
            ->assertViewHas('sharedFreeWeight', [$bins[3]->id => 0]);
        $picker = collect(WarehouseLocation::pickerOptions()[$this->warehouse->getKey()]);
        $this->assertStringContainsString('(0 kg szabad)', $picker->firstWhere('id', (string) $bins[3]->id)['label']);
        $this->assertEquals(250000, WarehouseStatistics::query()->whereKey($this->warehouse->getKey())->firstOrFail()->max_weight);
        $this->patchJson(route('warehouses.floor-plan.systems.update', [$this->warehouse, $id]),
            $this->rack(['version' => 2, 'system_weight' => 249]))->assertUnprocessable()->assertJsonValidationErrors('system_weight');
        $this->assertDatabaseCount('warehouse_placements', 5);
        $this->assertSame(2, (int) $this->warehouse->fresh()->floor_version);

        $transfer = InventoryTransfer::sole();
        $this->patch(route('transfers.complete', $transfer))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame(1, (int) $bins[2]->placements()->sum('quantity'));
    }

    public function test_floor_storage_has_pallet_positions_without_levels_and_other_warehouses_cannot_edit_it(): void
    {
        $url = route('warehouses.floor-plan.systems.store', $this->warehouse);
        $id = $this->postJson($url, $this->rack(['kind' => 'floor', 'code' => 'PAD-01', 'bins' => 4, 'level_weight' => 0, 'rack_height' => null]))->assertCreated()->json('id');
        $system = $this->warehouse->storageSystems()->findOrFail($id);
        $this->assertNull($system->height_cm);
        $this->assertCount(0, $system->levels);
        $this->assertSame([1, 1, 1, 1], $system->locations->pluck('max_pallet_slots')->all());
        $this->get(route('warehouses.locations.index', $this->warehouse))->assertOk()->assertSee('PAD-01')->assertDontSee('PAD-01-04');
        $this->get(route('warehouses.storage-systems.capacity', [$this->warehouse, $id]))->assertOk()
            ->assertSee('Padlóhelyek · 4 tárhely')->assertSee('PAD-01-04');
        $other = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        $this->patchJson(route('warehouses.floor-plan.systems.update', [$other, $id]), $this->rack())->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'worker']))->postJson($url, $this->rack())->assertForbidden();
    }

    public function test_named_rack_dimensions_and_codes_are_atomic_and_nominal_limits_are_independent(): void
    {
        $url = route('warehouses.floor-plan.systems.store', $this->warehouse);
        $data = $this->rack(['code' => 'a-01', 'bin_weight' => 150, 'level_weight' => 100, 'system_weight' => 90,
            'width' => 2, 'height' => 6, 'rotated' => true]);
        $this->postJson($url, $data + ['bin_dimensions' => ['width_cm' => 76]])->assertUnprocessable()->assertJsonValidationErrors('bin_dimensions');
        foreach (['warehouse_storage_systems', 'warehouse_storage_zones', 'warehouse_storage_levels', 'warehouse_locations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $id = $this->postJson($url, $data + ['bin_dimensions' => ['width_cm' => 70, 'height_cm' => 60], 'level_weights' => [2 => 175]])
            ->assertCreated()->json('id');
        $system = $this->warehouse->storageSystems()->findOrFail($id);
        $this->assertSame([2, 6, 300, 100], [$system->floor_width, $system->floor_height, $system->frontWidth(), $system->depth()]);
        $bins = $system->locations()->orderBy('code')->get();
        $this->assertCount(12, $bins);
        $this->assertSame('A-01-01-01', $bins->first()->code);
        $this->assertSame('A-01-03-04', $bins->last()->code);
        $this->assertSame([70, 100, 60, 150000], array_values(array_map('intval', $bins->first()->only(['width_cm', 'depth_cm', 'height_cm', 'max_weight_grams']))));
        $this->postJson($url, $data)->assertStatus(409);
        $this->postJson($url, array_replace($data, ['version' => 2, 'zone' => 'Másik zóna', 'y' => 7]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('warehouse_locations', 12);

        $bin = $bins->first();
        $endpoint = route('warehouses.storage-systems.bins.update', [$this->warehouse, $id, $bin->id]);
        $change = ['width_cm' => 90, 'depth_cm' => 90, 'height_cm' => 80, 'max_weight_kg' => 125, 'active' => true, 'lock_version' => 1];
        $this->patchJson($endpoint, array_replace($change, ['width_cm' => 91]))->assertUnprocessable()->assertJsonValidationErrors('bin_dimensions');
        $this->patchJson($endpoint, $change)->assertRedirect();
        $this->patchJson($endpoint, $change)->assertStatus(409);
        $this->patchJson(route('warehouses.floor-plan.systems.update', [$this->warehouse, $id]), collect($data)
            ->except(['bin_weight', 'level_weight'])->replace(['version' => 2, 'name' => 'Átnevezett állvány'])->all())->assertNoContent();
        $this->assertSame(125000, $bin->fresh()->max_weight_grams);
        $this->assertSame([100000, 175000, 100000], $system->levels()->pluck('max_weight_grams')->all());
        $front = route('warehouses.storage-systems.show', [$this->warehouse, $id, 'bin' => $bin->id]);
        $this->get($front)->assertOk()->assertSee('90 × 90 × 80 cm')->assertSee('125 kg')->assertSee('90 kg');
    }

    public function test_bin_categories_apply_to_stock_and_reservations_and_protect_existing_inventory(): void
    {
        $source = Warehouse::create(['warehouse_name' => 'Küldő']);
        $category = Category::create(['category_name' => 'Engedélyezett']);
        $other = Category::create(['category_name' => 'Másik kategória', 'parent_id' => $category->getKey()]);
        $product = Product::create(['sku' => 'RACK-CAT', 'product_name' => 'Mérőműszer', 'category_id' => $category->getKey(), 'price' => 1]);
        foreach ([$this->warehouse, $source] as $warehouse) {
            Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(), 'quantity' => 5]);
        }
        $id = $this->postJson(route('warehouses.floor-plan.systems.store', $this->warehouse), $this->rack())->assertCreated()->json('id');
        $bin = $this->warehouse->storageSystems()->findOrFail($id)->locations()->firstOrFail();
        $url = route('warehouses.storage-systems.bins.update', [$this->warehouse, $id, $bin->id]);
        $edit = ['width_cm' => 75, 'depth_cm' => 100, 'height_cm' => 75, 'max_weight_kg' => 50, 'active' => true];
        $this->patchJson($url, $edit + ['lock_version' => 1, 'categories' => [$other->getKey()]])->assertRedirect();
        $place = fn () => $this->postJson(route('warehouses.locations.place', [$this->warehouse, $bin]),
            ['request_key' => (string) Str::uuid(), 'product_id' => $product->getKey(), 'quantity' => 2, 'cargo_weight_kg' => 20]);
        $transfer = fn () => $this->postJson(route('transfers.store'), ['request_key' => (string) Str::uuid(),
            'product_id' => $product->getKey(), 'quantity' => 1, 'cargo_weight_kg' => 10,
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $this->warehouse->getKey(), 'to_location_id' => $bin->id]);
        $place()->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $transfer()->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->patchJson($url, $edit + ['lock_version' => 2, 'categories' => [$category->getKey()]])->assertRedirect();
        $place()->assertRedirect();
        $transfer()->assertRedirect();
        $this->patchJson($url, $edit + ['lock_version' => 3, 'categories' => [$other->getKey()]])->assertUnprocessable()->assertJsonValidationErrors('categories');
        $this->patchJson($url, array_replace($edit, ['active' => false, 'lock_version' => 3]))->assertUnprocessable();
        $this->putJson(route('products.update', $product), $product->only(['sku', 'product_name', 'price'])
            + ['category_id' => $other->getKey(), 'lock_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->get(route('warehouses.storage-systems.show', [$this->warehouse, $id]))->assertOk()->assertSee('Mérőműszer · RACK-CAT · 2 db')
            ->assertSee('2 db · 20 kg')->assertSee('10 kg');
        $this->patch(route('transfers.complete', InventoryTransfer::sole()))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(3, $bin->placements()->sum('quantity'));
        $this->actingAs(User::factory()->create(['role' => 'worker']))->patchJson($url, $edit + ['lock_version' => 3])->assertForbidden();
    }
}
