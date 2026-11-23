<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse, WarehouseLocation};
use App\Services\TransferCargoWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferCargoWeightTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $destination;
    private Product $product;
    private Stock $sourceStock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Cél']);
        $category = Category::create(['category_name' => 'Alkatrészek']);
        $this->product = Product::create([
            'product_name' => 'Tengely', 'sku' => 'ATM-S-1', 'price' => 1, 'category_id' => $category->getKey(),
        ]);
        $this->sourceStock = Stock::create([
            'warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 500,
        ]);
    }

    public function test_confirmed_calculation_reaches_putaway_and_the_same_weight_is_reserved_and_received(): void
    {
        $packaging = $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500']);
        $location = $this->location();
        $this->assertSame(5500, $packaging->grossWeightGrams());

        $this->getJson($this->recommendationUrl([
            'cargo_weight_source' => 'calculated', 'cargo_weight_kg' => '11', 'pallet_slots' => 1,
            'dimensions_cm' => ['width' => 20, 'depth' => 20, 'height' => 20],
        ]))->assertOk()
            ->assertJsonPath('recommendations.0.status', 'verified')
            ->assertJsonPath('recommendations.0.score.remaining_weight_grams', 9000)
            ->assertJsonPath('recommendations.0.score.remaining_pallet_slots', 0);

        $data = $this->transfer($location) + ['box_count' => 2, 'cargo_weight_source' => 'calculated', 'cargo_weight_kg' => '11'];
        $this->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $this->assertSame(300, $this->sourceStock->fresh()->quantity);
        $this->assertSame(11000, (int) $location->reservations()->sum('cargo_weight_grams'));

        $packaging->update(['gross_weight_kg' => '6.000']);
        $this->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseCount('inventory_transfer_requests', 1);
        $this->assertSame(300, $this->sourceStock->fresh()->quantity);

        $this->patch(route('transfers.complete', InventoryTransfer::sole()))->assertRedirect(route('transfers.index'));
        $this->assertSame(0, $location->reservations()->count());
        $this->assertDatabaseHas('warehouse_placements', [
            'warehouse_location_id' => $location->id, 'quantity' => 200, 'cargo_weight_grams' => 11000, 'pallet_slots' => 1,
        ]);
        $this->assertDatabaseHas('stocks', [
            'warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200,
        ]);
    }

    public function test_unknown_pallet_total_and_dimensions_are_conditional_despite_packaging_data(): void
    {
        $this->product->defaultPackaging()->create([
            'name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
            'width_cm' => 20, 'depth_cm' => 20, 'height_cm' => 20,
        ]);
        $this->location();

        $response = $this->getJson($this->recommendationUrl(['pallet_slots' => 1]))
            ->assertOk()->assertJsonPath('recommendations.0.status', 'conditional');
        $this->assertArrayHasKey('weight_input', $response->json('recommendations.0.checks.missing'));
        $this->assertArrayHasKey('cargo_dimensions', $response->json('recommendations.0.checks.missing'));

        $response = $this->getJson($this->recommendationUrl(['pallet_slots' => 1, 'cargo_weight_kg' => 12]))
            ->assertOk()
            ->assertJsonPath('recommendations.0.status', 'conditional')
            ->assertJsonPath('recommendations.0.score.remaining_weight_grams', 8000);
        $this->assertArrayNotHasKey('weight_input', $response->json('recommendations.0.checks.missing'));
    }

    public function test_invalid_calculation_is_rejected_on_both_endpoints(): void
    {
        $packaging = $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500']);
        $location = $this->location();

        $this->getJson($this->recommendationUrl(['cargo_weight_source' => 'calculated']))
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson(route('transfers.store'), $this->transfer($location) + ['cargo_weight_source' => 'calculated'])
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');

        foreach ([[200, 10], [150, '8.250']] as [$quantity, $kilograms]) {
            $data = ['cargo_weight_source' => 'calculated', 'cargo_weight_kg' => $kilograms];
            $this->getJson($this->recommendationUrl(['quantity' => $quantity] + $data))
                ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
            $this->postJson(route('transfers.store'), $this->transfer($location, $quantity) + $data)
                ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        }

        $packaging->update(['gross_weight_kg' => null]);
        $data = ['cargo_weight_source' => 'calculated', 'cargo_weight_kg' => 11];
        $this->getJson($this->recommendationUrl($data))->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson(route('transfers.store'), $this->transfer($location) + $data)
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');

        $packaging->update(['gross_weight_kg' => '999999.999']);
        $this->getJson($this->recommendationUrl(['cargo_weight_source' => 'calculated', 'cargo_weight_kg' => '1000000']))
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');

        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(500, $this->sourceStock->fresh()->quantity);
    }

    public function test_packaging_change_invalidates_a_previously_confirmed_manual_weight(): void
    {
        $packaging = $this->product->defaultPackaging()->create([
            'name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
        ]);
        $location = $this->location();
        $data = ['cargo_weight_packaging' => TransferCargoWeight::packagingRevision($packaging), 'cargo_weight_kg' => '12'];
        $recommendation = ['pallet_slots' => 1] + $data;
        $this->getJson($this->recommendationUrl($recommendation))->assertOk();

        $packaging->update(['gross_weight_kg' => '6.000']);
        $this->getJson($this->recommendationUrl($recommendation))
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_packaging');
        $this->postJson(route('transfers.store'), $this->transfer($location) + $data)
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_packaging');
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(500, $this->sourceStock->fresh()->quantity);

        $data['cargo_weight_packaging'] = TransferCargoWeight::packagingRevision($packaging);
        $this->post(route('transfers.store'), $this->transfer($location) + $data)->assertRedirect(route('transfers.index'));
        $this->assertSame(12000, (int) $location->reservations()->sum('cargo_weight_grams'));
    }

    public function test_new_packaging_invalidates_the_previous_empty_revision(): void
    {
        $data = ['cargo_weight_packaging' => TransferCargoWeight::packagingRevision(null), 'cargo_weight_kg' => '12'];
        $this->location();
        $this->getJson($this->recommendationUrl(['pallet_slots' => 1] + $data))->assertOk();

        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100]);
        $this->getJson($this->recommendationUrl(['pallet_slots' => 1] + $data))
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_packaging');
    }

    public function test_calculated_grams_still_have_to_fit_at_the_destination(): void
    {
        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.501']);
        $location = $this->location(11001);
        $data = ['cargo_weight_source' => 'calculated', 'cargo_weight_kg' => '11.002'];
        $this->getJson($this->recommendationUrl(['pallet_slots' => 1] + $data))->assertOk()->assertJsonPath('recommendations', []);
        $this->postJson(route('transfers.store'), $this->transfer($location) + $data)
            ->assertUnprocessable()->assertJsonPath('errors.cargo_weight_kg.0', 'Rekesz teherbírása túllépve. Túllépés: 0,001 kg.');
        $this->assertSame(500, $this->sourceStock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    private function recommendationUrl(array $overrides = []): string
    {
        return route('transfers.putaway-recommendations', $overrides + [
            'to_warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200,
        ]);
    }

    private function transfer(WarehouseLocation $location, int $quantity = 200): array
    {
        return [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'to_location_id' => $location->id, 'quantity' => $quantity, 'pallet_slots' => 1,
        ];
    }

    private function location(int $maxGrams = 20000): WarehouseLocation
    {
        $location = $this->destination->storageLocations()->create([
            'name' => 'Raklaphely', 'code' => 'P-01', 'kind' => 'pallet', 'max_weight_grams' => $maxGrams, 'max_pallet_slots' => 1,
        ]);
        $location->forceFill(['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        return $location;
    }
}
