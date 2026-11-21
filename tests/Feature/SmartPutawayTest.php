<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, SmartPutaway, WarehouseCapacity, WarehouseStorageDesigner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SmartPutawayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Warehouse $source;
    private Product $product;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrásraktár']);
        $this->user = User::factory()->create();
        $category = Category::create(['category_name' => 'Alkatrészek']);
        $this->product = Product::create(['product_name' => 'Tengely', 'sku' => 'PUT-1', 'price' => 1, 'category_id' => $category->getKey()]);
        foreach ([$this->warehouse, $this->source] as $warehouse) {
            Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 10]);
        }
    }

    public function test_finds_a_verified_location_in_the_requested_warehouse_and_does_not_write(): void
    {
        $target = $this->location('A-01');
        $inactive = $this->location('A-02', ['active' => false]);
        $foreign = $this->source->storageLocations()->create(['code' => 'A-03', 'name' => 'Külső tárhely', 'kind' => 'shelf', 'max_weight_grams' => 200000]);
        $before = $this->warehouse->storageLocations()->orderBy('id')->get()->modelKeys();

        $result = $this->recommend();

        $this->assertSame($this->warehouse->getKey(), $result['warehouse_id']);
        $this->assertSame(2, $result['quantity']);
        $this->assertSame([$target->id], array_column($result['recommendations'], 'location_id'));
        $this->assertSame('verified', $result['recommendations'][0]['status']);
        $this->assertContains('dimensions_fit', $result['recommendations'][0]['checks']['passed']);
        $this->assertSame(80000, $result['recommendations'][0]['score']['remaining_weight_grams']);
        $this->assertContains('A rakomány után 80 kg szabad teherbírás maradna.', $result['recommendations'][0]['reasons']);
        $this->assertSame(['Azonos kapacitásmaradéknál a kisebb fizikai térfogat előnyt élvez.'],
            $result['recommendations'][0]['ranking_notes']);
        $this->assertArrayHasKey('active', $this->rejected($result, $inactive)['checks']['failed']);
        $this->assertNotContains($foreign->id, array_column([...$result['recommendations'], ...$result['rejected']], 'location_id'));
        $this->assertSame($before, $this->warehouse->storageLocations()->orderBy('id')->get()->modelKeys());
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertEquals([10, 10], Stock::orderBy('warehouse_id')->pluck('quantity')->all());
    }

    public function test_excludes_overweight_bins_levels_and_racks_using_physical_stock_and_pending_reservations(): void
    {
        $localLimit = $this->location('REKESZ', ['max_weight_grams' => 10000]);
        [$reservedBin, $sameLevel] = $this->rack('SZINT', 0, 60, 200);
        [$loadedBin, $sameRack] = $this->rack('ALLVANY', 5, 200, 55);
        $this->reserve($reservedBin, 45);
        $this->place($loadedBin, 40);
        $fit = $this->location('ALKALMAS');

        $result = $this->recommend();

        $this->assertSame([$fit->id], array_column($result['recommendations'], 'location_id'));
        $this->assertArrayHasKey('location_weight', $this->rejected($result, $localLimit)['checks']['failed']);
        $this->assertArrayHasKey('level_weight', $this->rejected($result, $sameLevel)['checks']['failed']);
        $this->assertArrayHasKey('system_weight', $this->rejected($result, $sameRack)['checks']['failed']);
        $this->assertSame(15000, $this->rejected($result, $sameLevel)['free_weight_grams']);
        $this->assertSame(15000, $this->rejected($result, $sameRack)['free_weight_grams']);
    }

    public function test_rejects_incompatible_categories_and_insufficient_pallet_slots(): void
    {
        [$restricted] = $this->rack('KATEGORIA', 0, 120, 150);
        $other = Category::create(['category_name' => 'Más termékcsoport']);
        $restricted->allowedCategories()->attach($other);
        $pallet = $this->location('RAKLAP', ['kind' => 'pallet', 'max_pallet_slots' => 2]);
        $this->reserve($pallet, 10, 1);

        $shelfResult = $this->recommend();
        $this->assertArrayHasKey('category', $this->rejected($shelfResult, $restricted)['checks']['failed']);
        $palletResult = $this->recommend(['pallet_slots' => 2]);
        $this->assertSame([], $palletResult['recommendations']);
        $this->assertArrayHasKey('pallet_space', $this->rejected($palletResult, $pallet)['checks']['failed']);
        $this->assertSame(1, $this->rejected($palletResult, $pallet)['free_pallet_slots']);
        $this->assertArrayHasKey('pallet_kind', $this->rejected($palletResult, $restricted)['checks']['failed']);
    }

    public function test_prefers_existing_product_and_returns_at_most_five_in_stable_order(): void
    {
        $sameProduct = $this->location('Z-01', ['max_weight_grams' => 300000]);
        $this->place($sameProduct, 10);
        foreach (['E', 'D', 'C', 'B', 'A'] as $code) $this->location($code, ['max_weight_grams' => 40000]);
        $this->location('TÁGAS', ['max_weight_grams' => 150000]);

        $recommendations = $this->recommend(['dimensions_cm' => null])['recommendations'];

        $this->assertSame(['Z-01', 'A', 'B', 'C', 'D'], array_column($recommendations, 'code'));
        $this->assertTrue($recommendations[0]['score']['same_product']);
        $this->assertSame('conditional', $recommendations[0]['status']);
        $this->assertArrayHasKey('remaining_physical_space', $recommendations[0]['checks']['missing']);
    }

    public function test_verified_locations_precede_conditional_ones_even_with_a_worse_capacity_fit(): void
    {
        $occupied = $this->location('A', ['max_weight_grams' => 30000]);
        $this->place($occupied, 10);
        $reserved = $this->location('B', ['max_weight_grams' => 30000]);
        $this->reserve($reserved, 10);
        $this->location('C', ['max_weight_grams' => 19000]);
        $this->location('Z', ['max_weight_grams' => 200000]);

        $result = $this->recommend();
        [$verified, $withStock, $withReservation] = $result['recommendations'];
        $this->assertSame(['Z', 'A', 'B'], array_column($result['recommendations'], 'code'));
        $this->assertSame('verified', $verified['status']);
        $this->assertSame(['C'], array_column($result['rejected'], 'code'));

        foreach ([$withStock, $withReservation] as $candidate) {
            $this->assertSame('conditional', $candidate['status']);
            $this->assertArrayHasKey('remaining_physical_space', $candidate['checks']['missing']);
            $this->assertNotContains('dimensions_fit', $candidate['checks']['passed']);
        }
    }

    public function test_unknown_weight_and_unknown_pallet_need_independently_prevent_verification(): void
    {
        $this->location('A');
        $this->location('B', ['kind' => 'pallet', 'max_pallet_slots' => 1]);

        $unknownWeight = $this->recommend(['cargo_weight_kg' => null])['recommendations'][0];
        $this->assertSame('conditional', $unknownWeight['status']);
        $this->assertArrayHasKey('weight_input', $unknownWeight['checks']['missing']);
        $this->assertContains('pallet_kind', $unknownWeight['checks']['passed']);
        $this->assertContains('dimensions_fit', $unknownWeight['checks']['passed']);

        $unknownSlots = $this->recommend(['pallet_slots' => null])['recommendations'];
        $this->assertSame(['conditional', 'conditional'], array_column($unknownSlots, 'status'));
        foreach ($unknownSlots as $candidate) {
            $this->assertArrayHasKey('pallet_kind', $candidate['checks']['missing']);
            $this->assertContains('location_weight', $candidate['checks']['passed']);
            $this->assertContains('dimensions_fit', $candidate['checks']['passed']);
        }
    }

    public function test_dimensions_apply_to_the_entire_line_and_quantity_counts_units(): void
    {
        $this->location('A');
        $cargo = ['quantity' => 10, 'dimensions_cm' => ['width' => 80, 'depth' => 40, 'height' => 35]];

        $result = $this->recommend($cargo);
        $this->assertSame(10, $result['quantity']);
        $this->assertSame('verified', $result['recommendations'][0]['status']);
        $this->assertSame(80000, $result['recommendations'][0]['score']['remaining_weight_grams']);

        $unknownArrangement = $this->recommend(['dimensions_cm' => null] + $cargo);
        $this->assertSame('conditional', $unknownArrangement['recommendations'][0]['status']);
        $this->assertArrayHasKey('cargo_dimensions', $unknownArrangement['recommendations'][0]['checks']['missing']);
    }

    public function test_missing_physical_inputs_are_conditional_but_known_mismatches_are_rejected(): void
    {
        $target = $this->location('A');
        $noDimensions = $this->location('B', dimensions: []);
        $this->location('C', ['kind' => 'pallet', 'max_pallet_slots' => 1]);
        $result = $this->recommend(['cargo_weight_kg' => null, 'dimensions_cm' => ['width' => 30], 'pallet_slots' => null]);
        $candidates = array_column($result['recommendations'], null, 'code');
        $this->assertSame(['conditional', 'conditional', 'conditional'], array_column($result['recommendations'], 'status'));
        $this->assertArrayHasKey('weight_input', $candidates['A']['checks']['missing']);
        $this->assertArrayHasKey('cargo_dimensions', $candidates['A']['checks']['missing']);
        $this->assertNull($candidates['A']['score']['remaining_weight_grams']);
        $this->assertArrayHasKey('location_dimensions', $candidates['B']['checks']['missing']);
        $this->assertArrayHasKey('pallet_space', $candidates['C']['checks']['missing']);

        $tooWide = $this->recommend(['dimensions_cm' => ['width' => 101]]);
        $this->assertArrayHasKey('dimensions_fit', $this->rejected($tooWide, $target)['checks']['failed']);
        $this->assertSame([$noDimensions->id], array_column($tooWide['recommendations'], 'location_id'));
    }

    public function test_returns_no_recommendations_for_an_empty_warehouse_or_a_known_full_location(): void
    {
        $this->assertSame([], $this->recommend()['recommendations']);
        $location = $this->location('TELI', ['max_weight_grams' => 20000]);
        $this->reserve($location, 20);

        $result = $this->recommend(['cargo_weight_kg' => null]);

        $this->assertSame([], $result['recommendations']);
        $this->assertArrayHasKey('location_weight', $this->rejected($result, $location)['checks']['failed']);
    }

    public function test_booking_rechecks_capacity_when_an_earlier_recommendation_becomes_stale(): void
    {
        $location = $this->location('VERSENY', ['max_weight_grams' => 50000]);
        $this->assertSame($location->id, $this->recommend(['cargo_weight_kg' => 30])['recommendations'][0]['location_id']);
        $first = $this->reserve($location, 40);

        try {
            $this->reserve($location, 30);
            $this->fail('A foglalás a korábbi ajánlás ellenére már nem fér el.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cargo_weight_kg', $exception->errors());
        }
        $this->assertSame($first->id, InventoryTransfer::sole()->id);
        $this->assertSame([], $this->recommend(['cargo_weight_kg' => 30])['recommendations']);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->source->getKey(), 'quantity' => 9]);
    }

    public function test_manual_location_search_keeps_the_shared_capacity_usage_outside_its_filter(): void
    {
        [$reserved, $target] = $this->rack('SZURES', 0, 60, 200);
        $this->reserve($reserved, 45);
        $target->update(['name' => 'Északi tartalék']);
        $foreign = $this->source->storageLocations()->create([
            'code' => 'MASIK', 'name' => 'Északi tartalék', 'kind' => 'shelf', 'max_weight_grams' => 200000,
        ]);
        $engine = app(SmartPutaway::class);
        $cargo = ['quantity' => 2, 'cargo_weight_kg' => 20, 'pallet_slots' => 0];

        $result = $engine->assessLocations($this->warehouse, $this->product, $cargo, search: ' eszaki ');

        $this->assertSame([$target->id], array_column($result, 'location_id'));
        $this->assertSame('rejected', $result[0]['status']);
        $this->assertSame(15000, $result[0]['free_weight_grams']);
        $this->assertArrayHasKey('level_weight', $result[0]['checks']['failed']);
        $this->assertSame($result, $engine->assessLocations($this->warehouse, $this->product, $cargo, $target->id));
        $this->assertSame([], $engine->assessLocations($this->warehouse, $this->product, $cargo, $foreign->id));
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseCount('inventory_transfers', 1);
    }

    public function test_stock_projection_counts_only_placed_units_of_the_requested_product_and_uses_bounded_queries(): void
    {
        [$target, $inactive] = $this->rack('KESZLET', 0, 100, 150);
        $this->place($target, 5);
        $this->place($inactive, 5);
        $inactive->update(['active' => false]);
        $mixed = $this->location('VEGYES');
        $other = Product::factory()->create();
        $otherStock = Stock::create(['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $other->getKey(), 'quantity' => 90]);
        foreach ([$target, $mixed] as $bin) {
            $bin->placements()->create(['stock_id' => $otherStock->id, 'quantity' => 40, 'cargo_weight_grams' => 5000]);
        }
        $this->reserve($mixed, 5);
        $foreignBin = $this->source->storageLocations()->create([
            'code' => 'KULSO', 'name' => 'Külső tárhely', 'kind' => 'shelf', 'max_weight_grams' => 100000,
        ]);
        $foreignStock = Stock::where('warehouse_id', $this->source->getKey())->sole();
        $foreignBin->placements()->create(['stock_id' => $foreignStock->id, 'quantity' => 5, 'cargo_weight_grams' => 5000]);

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $first = collect($this->recommend()['recommendations'])->keyBy('location_id');
            $smallQueryCount = count(DB::getQueryLog());
            $this->assertSame('conditional', $first[$target->id]['status']);
            $this->assertSame([
                'bin' => ['before' => 1, 'after' => 3],
                'level' => ['before' => 2, 'after' => 4],
                'system' => ['before' => 2, 'after' => 4, 'kind' => 'rack'],
            ], $first[$target->id]['product_stock']);
            $this->assertSame(['bin' => ['before' => 0, 'after' => 2], 'level' => null, 'system' => null],
                $first[$mixed->id]['product_stock']);
            $this->assertFalse($first->has($foreignBin->id));
            $this->assertFalse($first->has($inactive->id));

            foreach (range(1, 30) as $index) $this->location('URES-'.$index);
            DB::flushQueryLog();
            $this->assertCount(5, $this->recommend()['recommendations']);
            $this->assertSame($smallQueryCount, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_contradictory_stock_is_unknown_rather_than_an_empty_bin(): void
    {
        $bin = $this->location('KESZLET');
        $this->place($bin, 1);
        Stock::where('warehouse_id', $this->warehouse->getKey())->update(['quantity' => 0]);

        $stock = $this->recommend()['recommendations'][0]['product_stock'];

        $this->assertSame(['before' => null, 'after' => null], $stock['bin']);
    }

    private function recommend(array $overrides = []): array
    {
        return app(SmartPutaway::class)->recommend($this->warehouse, $this->product, $overrides + [
            'quantity' => 2, 'cargo_weight_kg' => 20, 'pallet_slots' => 0,
            'dimensions_cm' => ['width' => 20, 'depth' => 20, 'height' => 20],
        ]);
    }

    private function rejected(array $result, WarehouseLocation $location): array
    {
        return collect($result['rejected'])->firstWhere('location_id', $location->id);
    }

    private function location(string $code, array $overrides = [], array $dimensions = ['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100]): WarehouseLocation
    {
        $location = $this->warehouse->storageLocations()->create($overrides + ['name' => $code, 'code' => $code,
            'kind' => 'shelf', 'max_weight_grams' => 100000]);
        $location->forceFill($dimensions)->save();

        return $location;
    }

    private function rack(string $code, int $x, int $levelLimit, int $systemLimit): array
    {
        $result = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
            'version' => $this->warehouse->fresh()->floor_version, 'kind' => 'rack', 'name' => $code, 'zone' => 'A',
            'code' => $code, 'width' => 4, 'height' => 2, 'rack_height' => 2, 'x' => $x, 'y' => 0,
            'levels' => 1, 'bins' => 2, 'bin_weight' => 100, 'level_weight' => $levelLimit, 'system_weight' => $systemLimit,
        ]);

        return $this->warehouse->storageSystems()->findOrFail($result['id'])->locations()->orderBy('code')->get()->all();
    }

    private function reserve(WarehouseLocation $location, int $weight, ?int $slots = null): InventoryTransfer
    {
        return app(InventoryService::class)->startTransfer(['from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->warehouse->getKey(),
            'product_id' => $this->product->getKey(), 'quantity' => 1, 'user_id' => $this->user->id,
            'to_location_id' => $location->id, 'cargo_weight_kg' => $weight, 'pallet_slots' => $slots]);
    }

    private function place(WarehouseLocation $location, int $weight): void
    {
        app(WarehouseCapacity::class)->place($location, ['product_id' => $this->product->getKey(), 'quantity' => 1, 'cargo_weight_kg' => $weight]);
    }
}
