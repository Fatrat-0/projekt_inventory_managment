<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse, WarehouseLocation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseFloorPlanTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private WarehouseLocation $shelf;
    private WarehouseLocation $pallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Tervrajz tesztraktár']);
        $this->shelf = $this->warehouse->storageLocations()->create(['code' => 'P-01', 'name' => 'Polc', 'kind' => 'shelf', 'max_weight_grams' => 80000]);
        $this->pallet = $this->warehouse->storageLocations()->create(['code' => 'R-01', 'name' => 'Raklapzóna', 'kind' => 'pallet', 'max_weight_grams' => 200000, 'max_pallet_slots' => 3]);
    }

    private function position(WarehouseLocation $location, int $x = 0, int $y = 0): array
    {
        return ['id' => $location->id, 'x' => $x, 'y' => $y, 'width' => 2, 'height' => 3];
    }

    public function test_managers_save_adjacent_locations_and_remove_positions_without_changing_stock(): void
    {
        $category = Category::create(['category_name' => 'Tervrajz termékek']);
        $product = Product::create(['sku' => 'FLOOR-STOCK', 'product_name' => 'Rögzítőkészlet', 'category_id' => $category->category_id, 'price' => 1]);
        $stock = Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 5]);
        $placement = $this->pallet->placements()->create(['stock_id' => $stock->id, 'quantity' => 3, 'cargo_weight_grams' => 50000, 'pallet_slots' => 1]);
        $url = route('warehouses.floor-plan.update', $this->warehouse);
        $data = ['columns' => 10, 'rows' => 8, 'version' => 1, 'positions' => [$this->position($this->shelf), $this->position($this->pallet, 2)]];
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->putJson($url, $data)->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(2, $this->pallet->fresh()->floor_x);
        $this->assertSame(0, $this->shelf->fresh()->floor_x);

        $view = $this->get(route('warehouses.floor-plan.show', $this->warehouse))->assertOk()->viewData('plan');
        $pallet = $view['locations']->firstWhere('id', $this->pallet->id);
        $this->assertSame(2, $pallet['freeSlots']);
        $this->assertSame('150', $pallet['freeWeight']);
        $this->assertSame([['id' => $product->product_id, 'name' => 'Rögzítőkészlet', 'quantity' => 3]], $pallet['products']->all());

        $this->putJson($url, array_replace($data, ['version' => 2]))->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, array_replace($data, ['version' => 2, 'positions' => []]))->assertOk()->assertJsonPath('version', 3);
        $this->assertNull($this->pallet->fresh()->floor_x);
        $this->assertNull($this->pallet->fresh()->floor_width);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(3, $placement->fresh()->quantity);
    }

    public function test_layout_rejects_overlap_out_of_bounds_duplicates_foreign_ids_and_stale_edits_atomically(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('warehouses.floor-plan.update', $this->warehouse);
        $base = ['columns' => 8, 'rows' => 6, 'version' => 1];
        $this->putJson($url, $base + ['positions' => [$this->position($this->shelf, 6, 3)]])->assertOk();
        $foreignWarehouse = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        $foreign = $foreignWarehouse->storageLocations()->create(['code' => 'I-01', 'name' => 'Idegen polc', 'kind' => 'shelf', 'max_weight_grams' => 1000]);
        $base['version'] = 2;
        foreach ([[$this->position($this->shelf, 7)], [$this->position($this->shelf), $this->position($this->pallet, 1)],
            [$this->position($this->shelf), $this->position($this->shelf, 3)], [$this->position($foreign)]] as $positions) {
            $this->putJson($url, $base + ['positions' => $positions])->assertUnprocessable()->assertJsonValidationErrors('positions');
        }
        $this->putJson($url, array_replace($base, ['version' => 1]) + ['positions' => []])->assertStatus(409)->assertJsonValidationErrors('version');
        $this->assertSame(6, $this->shelf->fresh()->floor_x);
        $this->assertNull($this->pallet->fresh()->floor_x);
        $this->assertSame(2, (int) $this->warehouse->fresh()->floor_version);
    }

    public function test_workers_can_read_the_plan_but_cannot_change_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->get(route('warehouses.floor-plan.show', $this->warehouse))->assertOk()->assertDontSee('Tervrajz szerkesztése');
        $this->putJson(route('warehouses.floor-plan.update', $this->warehouse), ['columns' => 10, 'rows' => 8, 'version' => 1, 'positions' => []])->assertForbidden();
    }
}
