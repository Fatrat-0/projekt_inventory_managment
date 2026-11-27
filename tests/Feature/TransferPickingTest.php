<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse, WarehouseLocation, WarehousePlacement, WarehouseStorageZone};
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TransferPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $destination;
    private Product $product;
    private Stock $stock;
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'PICK-01', 'price' => 1, 'category_id' => $category->getKey()]);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Cél']);
        $this->stock = Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(), 'quantity' => 100]);
        $this->worker = User::factory()->create(['role' => 'worker']);
        $this->worker->addWarehouseMembership($this->source->getKey(), ['supervisor']);
        $this->worker->addWarehouseMembership($this->destination->getKey(), ['receiving_operator']);
    }

    public function test_source_lookup_requires_transfer_permissions_and_valid_input(): void
    {
        $this->getJson($this->sourcesUrl(10))->assertUnauthorized();
        $this->actingAs($this->worker->forceFill(['role' => 'viewer']))
            ->getJson($this->sourcesUrl(10))->assertForbidden();
        $this->actingAs($this->worker->forceFill(['role' => 'worker']))->getJson($this->sourcesUrl(0))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_lookup_groups_placements_and_suggests_few_bins_in_deterministic_order(): void
    {
        $this->stock->update(['quantity' => 22, 'quarantined_quantity' => 4]);
        $later = $this->location('B-02');
        $first = $this->location('B-01');
        $this->place($later, 7, 7000);
        $this->place($first, 3, 3000);
        $this->place($first, 4, 4000);
        $this->place($this->location('B-03'), 4, 4000);

        $response = $this->actingAs($this->worker)->getJson($this->sourcesUrl(8))->assertOk()
            ->assertJsonPath('available_quantity', 18)
            ->assertJsonPath('quarantined_quantity', 4)
            ->assertJsonPath('suggestion', [
                ['location_id' => $first->id, 'quantity' => 7],
                ['location_id' => $later->id, 'quantity' => 1],
            ]);
        $sources = collect($response->json('sources'));
        $this->assertSame(7, $sources->firstWhere('location_id', $first->id)['stored_quantity']);
        $this->assertSame(0, $sources->firstWhere('location_id', $first->id)['reserved_quantity']);
        $this->assertSame(7, $sources->firstWhere('location_id', $first->id)['available_quantity']);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_source_allocations', 0);
        $this->assertDatabaseCount('warehouse_placements', 4);
        $this->assertSame(22, $this->stock->refresh()->quantity);
    }

    public function test_full_pick_removes_placement_and_records_its_original_identifiers(): void
    {
        $bin = $this->location('P-01', slots: 4);
        $placement = $this->place($bin, 30, 15000, 2);

        $transfer = $this->start(30, [$this->allocation($bin, 30)]);

        $this->assertModelMissing($placement);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', [
            'transfer_id' => $transfer->id, 'stock_id' => $this->stock->id, 'warehouse_location_id' => $bin->id,
            'source_placement_id' => $placement->id, 'location_code' => $bin->code, 'location_name' => $bin->name,
            'quantity' => 30, 'cargo_weight_grams' => 15000, 'pallet_slots' => 2,
        ]);
        $this->assertSame(70, $this->stock->refresh()->quantity);
        $usage = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame(100000, $usage->freeWeight());
        $this->assertSame(4, $usage->freeSlots());
    }

    public function test_partial_pick_updates_quantity_and_weight_and_keeps_occupied_pallet_slots(): void
    {
        $bin = $this->location('P-01', slots: 4);
        $placement = $this->place($bin, 100, 50001, 2);

        $transfer = $this->start(20, [$this->allocation($bin, 20)]);

        $this->assertSame(80, $this->stock->refresh()->quantity);
        $this->assertDatabaseHas('warehouse_placements', ['id' => $placement->id, 'quantity' => 80, 'cargo_weight_grams' => 40001, 'pallet_slots' => 2]);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', [
            'transfer_id' => $transfer->id, 'quantity' => 20, 'cargo_weight_grams' => 10000, 'pallet_slots' => 0,
        ]);
        $usage = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame(59999, $usage->freeWeight());
        $this->assertSame(2, $usage->freeSlots());
    }

    public function test_pick_from_multiple_bins_and_multiple_placements_per_bin(): void
    {
        $first = $this->location('B-01');
        $second = $this->location('B-02');
        $this->place($first, 10, 10000);
        $this->place($first, 20, 20000);
        $this->place($second, 70, 70000);

        $transfer = $this->start(35, [$this->allocation($first, 30), $this->allocation($second, 5)]);

        $this->assertSame(65, $this->stock->refresh()->quantity);
        $this->assertSame(0, $first->placements()->count());
        $this->assertSame(65, $second->placements()->sole()->quantity);
        $this->assertSame(3, $transfer->sourceAllocations()->count());
        $this->assertSame(35, (int) $transfer->sourceAllocations()->sum('quantity'));
        $this->assertSame(35000, (int) $transfer->sourceAllocations()->sum('cargo_weight_grams'));
    }

    public function test_stock_without_a_bin_can_be_picked_but_quarantined_units_cannot(): void
    {
        $this->stock->update(['quantity' => 20, 'quarantined_quantity' => 5]);
        $this->actingAs($this->worker)->getJson($this->sourcesUrl(15))->assertOk()
            ->assertJsonPath('available_quantity', 15)
            ->assertJsonPath('suggestion', [['location_id' => null, 'quantity' => 15]]);
        $this->postJson(route('transfers.store'), $this->data(16, [$this->allocation(null, 16)]))->assertUnprocessable();

        $transfer = $this->start(15, [$this->allocation(null, 15)]);

        $this->assertSame(5, $this->stock->refresh()->quantity);
        $this->assertSame(5, $this->stock->quarantined_quantity);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', [
            'transfer_id' => $transfer->id, 'stock_id' => $this->stock->id, 'warehouse_location_id' => null,
            'source_placement_id' => null, 'quantity' => 15, 'cargo_weight_grams' => null,
        ]);
    }

    public function test_assigned_and_unassigned_quantities_can_be_combined(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 90, 90000);

        $transfer = $this->start(25, [$this->allocation(null, 10), $this->allocation($bin, 15)]);

        $this->assertSame(75, $this->stock->refresh()->quantity);
        $this->assertSame(75, $bin->placements()->sole()->quantity);
        $this->assertSame(10, $transfer->sourceAllocations()->whereNull('warehouse_location_id')->sole()->quantity);
        $this->assertSame(15, $transfer->sourceAllocations()->where('warehouse_location_id', $bin->id)->sole()->quantity);
    }

    public function test_wrong_totals_and_foreign_or_insufficient_bins_are_rejected(): void
    {
        $bin = $this->location('B-01');
        $otherWarehouse = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        $foreignBin = $this->location('B-01', $otherWarehouse);
        $this->place($bin, 10, 10000);
        $this->actingAs($this->worker);

        foreach ([
            [$this->allocation($bin, 9)],
            [$this->allocation($foreignBin, 11)],
            [$this->allocation($bin, 11)],
            [$this->allocation($bin, 5), $this->allocation($bin, 6)],
        ] as $allocations) {
            $this->postJson(route('transfers.store'), $this->data(11, $allocations))
                ->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        }

        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertSame(10, $bin->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_source_allocations', 0);
    }

    public function test_pending_outgoing_is_deducted_once_and_incoming_capacity_is_not_pickable(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 100, 50000);
        $incomingWarehouse = Warehouse::create(['warehouse_name' => 'Bejövő áru forrása']);
        Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $incomingWarehouse->getKey(), 'quantity' => 20]);
        app(InventoryService::class)->startTransfer([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $incomingWarehouse->getKey(),
            'to_warehouse_id' => $this->source->getKey(), 'to_location_id' => $bin->id, 'cargo_weight_kg' => 10,
            'quantity' => 20, 'user_id' => $this->worker->id,
        ]);
        $this->start(60, [$this->allocation($bin, 60)]);

        $response = $this->getJson($this->sourcesUrl(40))->assertOk()->assertJsonPath('available_quantity', 40);
        $source = collect($response->json('sources'))->firstWhere('location_id', $bin->id);
        $this->assertSame(40, $source['stored_quantity']);
        $this->assertSame(40, $source['available_quantity']);
        $this->assertSame(0, $source['reserved_quantity']);
        $this->assertSame(20, (int) $bin->reservations()->sum('quantity'));
        $this->postJson(route('transfers.store'), $this->data(41, [$this->allocation($bin, 41)]))->assertUnprocessable();
        $this->assertSame(40, $this->stock->refresh()->quantity);
    }

    public function test_disabled_bin_is_reported_but_cannot_be_suggested_or_picked(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 100, 50000);
        $bin->update(['active' => false]);

        $response = $this->actingAs($this->worker)->getJson($this->sourcesUrl(10))->assertOk()
            ->assertJsonPath('available_quantity', 0)->assertJsonPath('suggestion', []);
        $source = collect($response->json('sources'))->firstWhere('location_id', $bin->id);
        $this->assertSame(100, $source['stored_quantity']);
        $this->assertSame(0, $source['available_quantity']);
        $this->postJson(route('transfers.store'), $this->data(10, [$this->allocation($bin, 10)]))
            ->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertSame(100, $bin->placements()->sole()->quantity);
    }

    public function test_same_request_does_not_pick_twice_and_rejects_changed_allocation(): void
    {
        $first = $this->location('B-01');
        $second = $this->location('B-02');
        $this->place($first, 50, 50000);
        $this->place($second, 50, 50000);
        $data = $this->data(10, [$this->allocation($first, 10)]);

        $this->actingAs($this->worker)->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $this->post(route('transfers.store'), $data)->assertRedirect(route('transfers.index'));
        $this->postJson(route('transfers.store'), array_replace($data, ['source_allocations' => [$this->allocation($second, 10)]]))
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');

        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseCount('inventory_transfer_source_allocations', 1);
        $this->assertSame(90, $this->stock->refresh()->quantity);
        $this->assertSame(40, $first->placements()->sole()->quantity);
        $this->assertSame(50, $second->placements()->sole()->quantity);
    }

    public function test_cancellation_restores_partial_placement_and_rejects_a_second_finalization(): void
    {
        $bin = $this->location('P-01', slots: 4);
        $placement = $this->place($bin, 100, 50001, 2);
        $transfer = $this->start(20, [$this->allocation($bin, 20)]);

        $this->patch(route('transfers.cancel', $transfer))->assertRedirect(route('transfers.index'));
        $this->patchJson(route('transfers.cancel', $transfer))->assertUnprocessable()->assertJsonValidationErrors('transfer');
        $this->patchJson(route('transfers.complete', $transfer))->assertUnprocessable()->assertJsonValidationErrors('transfer');

        $this->assertSame('cancelled', $transfer->refresh()->status);
        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertSame(1, $bin->placements()->count());
        $this->assertDatabaseHas('warehouse_placements', ['id' => $placement->id, 'quantity' => 100, 'cargo_weight_grams' => 50001, 'pallet_slots' => 2]);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', ['transfer_id' => $transfer->id, 'returned_to_location' => true]);
    }

    public function test_cancellation_leaves_stock_unassigned_if_the_original_bin_no_longer_has_capacity(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 60, 60000);
        $transfer = $this->start(60, [$this->allocation($bin, 60)]);
        $this->place($bin, 40, 100000);

        $this->patch(route('transfers.cancel', $transfer))->assertRedirect(route('transfers.index'));

        $this->assertSame('cancelled', $transfer->refresh()->status);
        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertSame(40, $bin->placements()->sole()->quantity);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', [
            'transfer_id' => $transfer->id, 'warehouse_location_id' => $bin->id, 'quantity' => 60, 'returned_to_location' => false,
        ]);
        $response = $this->getJson($this->sourcesUrl(60))->assertOk();
        $unassigned = collect($response->json('sources'))->first(fn (array $source) => $source['location_id'] === null);
        $this->assertSame(60, $unassigned['available_quantity']);
    }

    public function test_cancellation_merges_placement_without_double_counting_level_and_rack_load(): void
    {
        $zone = WarehouseStorageZone::create(['warehouse_id' => $this->source->getKey(), 'name' => 'A zóna']);
        $rack = $zone->systems()->create([
            'name' => 'A állvány', 'kind' => 'rack', 'max_weight_grams' => 60000,
            'floor_x' => 0, 'floor_y' => 0, 'floor_width' => 2, 'floor_height' => 2,
        ]);
        $firstLevel = $rack->levels()->create(['number' => 1, 'max_weight_grams' => 55000]);
        $secondLevel = $rack->levels()->create(['number' => 2, 'max_weight_grams' => 5000]);
        $original = $this->location('A-01-01');
        $sameLevel = $this->location('A-01-02');
        $differentLevel = $this->location('A-02-01');
        foreach ([$original, $sameLevel, $differentLevel] as $bin) {
            $bin->forceFill(['system_id' => $rack->id, 'level_id' => $bin->is($differentLevel) ? $secondLevel->id : $firstLevel->id])->save();
        }
        $placement = $this->place($original, 50, 50000);
        $this->place($sameLevel, 5, 5000);
        $this->place($differentLevel, 5, 5000);
        $transfer = $this->start(20, [$this->allocation($original, 20)]);

        $this->patch(route('transfers.cancel', $transfer))->assertRedirect(route('transfers.index'));

        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertSame(1, $original->placements()->count());
        $this->assertSame(50000, $placement->refresh()->cargo_weight_grams);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', ['transfer_id' => $transfer->id, 'returned_to_location' => true]);
    }

    public function test_deleted_source_bin_keeps_historical_code_and_cancellation_returns_unassigned_stock(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 30, 15000);
        $transfer = $this->start(30, [$this->allocation($bin, 30)]);
        $bin->delete();

        $this->patch(route('transfers.cancel', $transfer))->assertRedirect(route('transfers.index'));
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee($bin->code);

        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', [
            'transfer_id' => $transfer->id, 'warehouse_location_id' => null, 'location_code' => $bin->code,
            'location_name' => $bin->name, 'quantity' => 30, 'returned_to_location' => false,
        ]);
    }

    public function test_receipt_preserves_source_history_and_uses_the_existing_destination_reservation(): void
    {
        $bin = $this->location('B-01');
        $this->place($bin, 100, 50000);
        $targetBin = $this->location('C-01', $this->destination);
        $transfer = $this->start(25, [$this->allocation($bin, 25)], ['to_location_id' => $targetBin->id, 'cargo_weight_kg' => 12]);

        $this->assertSame(12000, (int) $targetBin->reservations()->sum('cargo_weight_grams'));
        $this->assertSame(0, $targetBin->placements()->count());
        $this->patch(route('transfers.complete', $transfer))->assertRedirect(route('transfers.index'));
        $this->patchJson(route('transfers.complete', $transfer))->assertUnprocessable()->assertJsonValidationErrors('transfer');
        $this->patchJson(route('transfers.cancel', $transfer))->assertUnprocessable()->assertJsonValidationErrors('transfer');

        $this->assertSame('completed', $transfer->refresh()->status);
        $this->assertSame(0, $targetBin->reservations()->count());
        $this->assertSame(75, $this->stock->refresh()->quantity);
        $this->assertSame(75, $bin->placements()->sole()->quantity);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 25]);
        $this->assertDatabaseHas('warehouse_placements', ['warehouse_location_id' => $targetBin->id, 'quantity' => 25, 'cargo_weight_grams' => 12000]);
        $this->assertDatabaseHas('inventory_transfer_source_allocations', ['transfer_id' => $transfer->id, 'location_code' => $bin->code, 'quantity' => 25, 'returned_to_location' => null]);
    }

    public function test_late_dispatch_failure_rolls_back_stock_placements_allocations_and_request(): void
    {
        $bin = $this->location('B-01');
        $placement = $this->place($bin, 60, 60000);
        $targetBin = $this->location('C-01', $this->destination);
        InventoryTransfer::created(function (): never {
            throw new RuntimeException('late transfer write failure');
        });

        try {
            app(InventoryService::class)->startTransfer($this->data(20, [$this->allocation($bin, 20)], [
                'to_location_id' => $targetBin->id, 'cargo_weight_kg' => 20, 'user_id' => $this->worker->id,
            ]), (string) Str::uuid());
            $this->fail('The dispatch should have failed during persistence.');
        } catch (RuntimeException $e) {
            $this->assertSame('late transfer write failure', $e->getMessage());
        }

        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertDatabaseHas('warehouse_placements', ['id' => $placement->id, 'quantity' => 60, 'cargo_weight_grams' => 60000]);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_source_allocations', 0);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertSame(0, $targetBin->reservations()->count());
    }

    public function test_legacy_transfer_without_allocations_can_be_read_and_received(): void
    {
        $this->stock->update(['quantity' => 94]);
        $transfer = InventoryTransfer::create([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(), 'quantity' => 6, 'user_id' => $this->worker->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->worker)->get(route('transfers.show', $transfer))->assertOk();
        $this->patch(route('transfers.complete', $transfer))->assertRedirect(route('transfers.index'));

        $this->assertDatabaseCount('inventory_transfer_source_allocations', 0);
        $this->assertSame('completed', $transfer->refresh()->status);
        $this->assertSame(94, $this->stock->refresh()->quantity);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->destination->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 6]);
    }

    private function sourcesUrl(int $quantity): string
    {
        return route('transfers.picking-sources', [
            'from_warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => $quantity,
        ]);
    }

    private function location(string $code, ?Warehouse $warehouse = null, ?int $slots = null): WarehouseLocation
    {
        return ($warehouse ?? $this->source)->storageLocations()->create([
            'code' => $code, 'name' => 'Tárhely '.$code, 'kind' => $slots === null ? 'shelf' : 'pallet',
            'max_weight_grams' => 100000, 'max_pallet_slots' => $slots,
        ]);
    }

    private function place(WarehouseLocation $bin, int $quantity, int $grams, int $slots = 0): WarehousePlacement
    {
        return $bin->placements()->create(['stock_id' => $this->stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $grams, 'pallet_slots' => $slots]);
    }

    private function allocation(?WarehouseLocation $bin, int $quantity): array
    {
        return ['location_id' => $bin?->id, 'quantity' => $quantity];
    }

    private function data(int $quantity, array $allocations, array $overrides = []): array
    {
        return $overrides + [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'quantity' => $quantity, 'source_allocations' => $allocations,
        ];
    }

    private function start(int $quantity, array $allocations, array $overrides = []): InventoryTransfer
    {
        $this->actingAs($this->worker)->post(route('transfers.store'), $this->data($quantity, $allocations, $overrides))
            ->assertRedirect(route('transfers.index'))->assertSessionHasNoErrors();

        return InventoryTransfer::latest('id')->firstOrFail();
    }
}
