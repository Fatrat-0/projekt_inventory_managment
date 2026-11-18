<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse, WarehouseLocation, WarehousePlacement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferPutawayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $destination;
    private Product $product;
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Cél']);
        $category = Category::create(['category_name' => 'Alkatrészek']);
        $this->product = Product::create(['product_name' => 'Tengely', 'sku' => 'ATM-PUT-1', 'price' => 1, 'category_id' => $category->getKey()]);
        $this->worker = User::factory()->create(['role' => 'worker']);
        Stock::create(['warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 10]);
    }

    public function test_source_options_exclude_quarantined_and_include_pickable_assigned_stock(): void
    {
        $assignedWarehouse = Warehouse::create(['warehouse_name' => 'Tárhelyhez kötött készlet']);
        Stock::create(['warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 3, 'quarantined_quantity' => 3]);
        $assigned = Stock::create(['warehouse_id' => $assignedWarehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 4]);
        $location = $assignedWarehouse->storageLocations()->create(['name' => 'Polc', 'code' => 'F-01', 'kind' => 'shelf', 'max_weight_grams' => 50000]);
        WarehousePlacement::create(['warehouse_location_id' => $location->id, 'stock_id' => $assigned->id, 'quantity' => 4, 'cargo_weight_grams' => 1000]);

        $this->actingAs($this->worker)->get(route('transfers.create'))->assertOk()
            ->assertViewHas('sourceProducts', function ($options) use ($assignedWarehouse) {
                $sources = $options->toArray();

                return count($sources) === 2
                    && ($sources[$this->source->getKey()] ?? null) === [(string) $this->product->getKey()]
                    && ($sources[$assignedWarehouse->getKey()] ?? null) === [(string) $this->product->getKey()];
            });
    }

    public function test_recommendations_use_transfer_permissions_and_validate_identifiers_and_cargo(): void
    {
        $this->getJson($this->recommendationUrl())->assertUnauthorized();
        $this->actingAs($this->worker->forceFill(['role' => 'viewer']))
            ->getJson($this->recommendationUrl())->assertForbidden();
        $this->actingAs($this->worker->forceFill(['role' => 'worker']))
            ->getJson($this->recommendationUrl(['to_warehouse_id' => $this->destination->getKey() + 10, 'product_id' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['to_warehouse_id', 'product_id']);
        $this->getJson($this->recommendationUrl(['quantity' => 0, 'cargo_weight_kg' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'cargo_weight_kg']);
    }

    public function test_recommendations_are_read_only_ranked_and_limited_to_the_destination(): void
    {
        $verified = $this->location('B-ELLENORZOTT');
        $conditional = $this->location('A-FELTETELES', dimensions: false);
        $this->location('BETELT', limit: 10000);
        $this->source->storageLocations()->create(['name' => 'Forráshely', 'code' => 'KULSO', 'kind' => 'shelf', 'max_weight_grams' => 50000]);

        $response = $this->actingAs($this->worker)->getJson($this->recommendationUrl())->assertOk()
            ->assertJsonCount(2, 'recommendations')
            ->assertJsonPath('recommendations.0.location_id', $verified->id)
            ->assertJsonPath('recommendations.0.status', 'verified')
            ->assertJsonPath('recommendations.0.free_weight_grams', 50000)
            ->assertJsonPath('recommendations.0.score.remaining_weight_grams', 20000)
            ->assertJsonPath('recommendations.0.positioned', false)
            ->assertJsonPath('recommendations.0.plan_url', route('warehouses.floor-plan.show', ['warehouse' => $this->destination, 'location' => $verified->id]).'#floor-inspector')
            ->assertJsonPath('recommendations.1.location_id', $conditional->id)
            ->assertJsonPath('recommendations.1.status', 'conditional');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->json('recommendations.0.reasons'));

        $unknown = $this->getJson($this->recommendationUrl(['cargo_weight_kg' => null, 'pallet_slots' => null]))->assertOk();
        $candidate = collect($unknown->json('recommendations'))->firstWhere('location_id', $verified->id);
        $this->assertSame('conditional', $candidate['status']);
        $this->assertArrayHasKey('weight_input', $candidate['checks']['missing']);
        $this->assertArrayHasKey('pallet_kind', $candidate['checks']['missing']);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseCount('warehouse_locations', 4);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->source->getKey(), 'quantity' => 10]);
    }

    public function test_identical_warehouse_ids_are_rejected_in_hungarian_even_with_mixed_json_types(): void
    {
        $data = ['request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => (string) $this->source->getKey(), 'quantity' => 1];

        $this->actingAs($this->worker)->postJson(route('transfers.store'), $data)
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_warehouse_id.0', 'A forrásraktár és a célraktár nem lehet ugyanaz.');
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->source->getKey(), 'quantity' => 10]);
    }

    public function test_a_destination_location_requires_weight_even_if_client_validation_is_bypassed(): void
    {
        $location = $this->location('A-01');
        $this->actingAs($this->worker)->postJson(route('transfers.store'), [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'quantity' => 1, 'to_location_id' => $location->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.cargo_weight_kg.0', 'Add meg a rakomány teljes tömegét az indításhoz.');
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->source->getKey(), 'quantity' => 10]);
    }

    public function test_stale_recommendation_is_rejected_and_retry_and_receipt_book_only_once(): void
    {
        $location = $this->location('A-01');
        $this->actingAs($this->worker)->getJson($this->recommendationUrl())->assertOk()
            ->assertJsonPath('recommendations.0.location_id', $location->id);

        $transfer = [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'quantity' => 2, 'to_location_id' => $location->id, 'cargo_weight_kg' => 30,
        ];
        $this->post(route('transfers.store'), $transfer)->assertRedirect(route('transfers.index'));
        $this->post(route('transfers.store'), $transfer)->assertRedirect(route('transfers.index'));

        $second = array_replace($transfer, ['request_key' => (string) Str::uuid()]);
        $this->from(route('transfers.create'))->post(route('transfers.store'), $second)
            ->assertRedirect(route('transfers.create'))->assertSessionHasErrors('cargo_weight_kg');
        $this->getJson($this->recommendationUrl())->assertOk()->assertJsonPath('recommendations', []);

        $booking = InventoryTransfer::sole();
        $this->assertSame(30000, (int) $location->reservations()->sum('cargo_weight_grams'));
        $this->assertDatabaseCount('inventory_transfer_requests', 1);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->source->getKey(), 'quantity' => 8]);

        $this->patch(route('transfers.complete', $booking))->assertRedirect(route('transfers.index'));
        $this->patchJson(route('transfers.complete', $booking))->assertUnprocessable()->assertJsonValidationErrors('transfer');
        $this->assertSame(0, $location->reservations()->count());
        $this->assertDatabaseCount('warehouse_placements', 1);
        $this->assertDatabaseHas('warehouse_placements', ['warehouse_location_id' => $location->id, 'quantity' => 2]);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->destination->getKey(), 'quantity' => 2]);
    }

    private function recommendationUrl(array $overrides = []): string
    {
        return route('transfers.putaway-recommendations', $overrides + [
            'to_warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(),
            'quantity' => 2, 'cargo_weight_kg' => 30, 'pallet_slots' => 0,
            'dimensions_cm' => ['width' => 20, 'depth' => 20, 'height' => 20],
        ]);
    }

    private function location(string $code, int $limit = 50000, bool $dimensions = true): WarehouseLocation
    {
        $location = $this->destination->storageLocations()->create(['name' => $code, 'code' => $code, 'kind' => 'shelf', 'max_weight_grams' => $limit]);
        if ($dimensions) $location->forceFill(['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        return $location;
    }
}
