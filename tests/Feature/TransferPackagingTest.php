<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferPackagingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $destination;
    private Product $product;
    private Stock $sourceStock;

    protected function setUp(): void
    {
        parent::setUp();
        $worker = User::factory()->create(['role' => 'worker']);
        $this->actingAs($worker);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Cél']);
        $worker->addWarehouseMembership($this->source->getKey(), ['supervisor']);
        $worker->addWarehouseMembership($this->destination->getKey(), ['receiving_operator']);
        $category = Category::create(['category_name' => 'Alkatrészek']);
        $this->product = Product::create(['product_name' => 'Tengely', 'sku' => 'ATM-CS-1', 'price' => 1, 'category_id' => $category->getKey()]);
        $this->sourceStock = Stock::create(['warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 300]);
    }

    public function test_box_transfer_books_pieces_and_retry_after_packaging_edit_is_idempotent(): void
    {
        $packaging = $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100]);
        $data = $this->transfer(200, 2);

        $this->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $transfer = InventoryTransfer::sole();
        $this->assertSame(200, $transfer->quantity);
        $this->assertSame(100, $this->sourceStock->fresh()->quantity);

        $packaging->update(['pieces_per_package' => 120]);
        $this->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $this->postJson(route('transfers.store'), $this->transfer(100, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('box_count');
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseCount('inventory_transfer_requests', 1);
        $this->assertSame(100, $this->sourceStock->fresh()->quantity);

        $this->patch(route('transfers.complete', $transfer))->assertRedirect(route('transfers.index'));
        $this->assertDatabaseHas('stocks', [
            'warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200,
        ]);
    }

    public function test_box_transfer_still_requires_enough_stock_in_pieces(): void
    {
        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 100]);
        $this->sourceStock->update(['quantity' => 30]);

        $this->postJson(route('transfers.store'), $this->transfer(100, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(30, $this->sourceStock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_conversion_rejects_fractional_counts_and_totals_above_stock_integer_range(): void
    {
        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 2147483647]);

        $this->postJson(route('transfers.store'), $this->transfer(100) + ['box_count' => '1.5'])
            ->assertUnprocessable()->assertJsonValidationErrors('box_count');
        $this->postJson(route('transfers.store'), $this->transfer(2147483647, 2))
            ->assertUnprocessable()->assertJsonValidationErrors('box_count');
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
    }

    public function test_product_without_packaging_accepts_existing_piece_requests_only(): void
    {
        $this->postJson(route('transfers.store'), $this->transfer(1, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('box_count');
        $this->post(route('transfers.store'), $this->transfer(1))->assertRedirect(route('transfers.index'));

        $this->assertSame(1, InventoryTransfer::sole()->quantity);
        $this->assertSame(299, $this->sourceStock->fresh()->quantity);
    }

    public function test_package_dimensions_and_weight_do_not_stand_in_for_whole_cargo(): void
    {
        $this->product->defaultPackaging()->create([
            'name' => 'Doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.000',
            'width_cm' => 20, 'depth_cm' => 20, 'height_cm' => 20,
        ]);
        $location = $this->destination->storageLocations()->create([
            'name' => 'Polc', 'code' => 'P-01', 'kind' => 'shelf', 'max_weight_grams' => 50000,
        ]);
        $location->forceFill(['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        $response = $this->getJson(route('transfers.putaway-recommendations', [
            'product_id' => $this->product->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'quantity' => 200, 'pallet_slots' => 0,
        ]))->assertOk()->assertJsonPath('recommendations.0.status', 'conditional');
        $this->assertArrayHasKey('weight_input', $response->json('recommendations.0.checks.missing'));
        $this->assertArrayHasKey('cargo_dimensions', $response->json('recommendations.0.checks.missing'));
    }

    private function transfer(int $quantity, ?int $boxes = null): array
    {
        $data = [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(), 'quantity' => $quantity,
        ];
        if ($boxes !== null) $data['box_count'] = $boxes;

        return $data;
    }
}
