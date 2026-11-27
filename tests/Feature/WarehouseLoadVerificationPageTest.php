<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse, WarehouseLocation, WarehousePlacement};
use App\Services\WarehouseLoadVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseLoadVerificationPageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Warehouse $other;
    private WarehouseLocation $location;
    private WarehousePlacement $row;
    private WarehousePlacement $neighbour;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->warehouse = Warehouse::factory()->create(['warehouse_name' => 'Ellenőrzendő raktár']);
        $this->other = Warehouse::factory()->create(['warehouse_name' => 'Másik raktár']);
        Category::factory()->create();
        $product = Product::factory()->create(['product_name' => 'Megmaradt tétel', 'sku' => 'MERES-01']);
        $otherProduct = Product::factory()->create(['product_name' => 'Második termék', 'sku' => 'MERES-02']);
        $this->location = $this->warehouse->storageLocations()->create([
            'code' => 'SZ-01', 'name' => 'Alsó polc', 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 14000,
        ]);
        $this->location->forceFill(['floor_x' => 1, 'floor_y' => 1, 'floor_width' => 2, 'floor_height' => 1])->save();
        $this->row = $this->place($product, 6, 10000);
        $this->neighbour = $this->place($otherProduct, 2, 2000);
    }

    public function test_the_list_and_bin_entries_point_to_specific_rows_and_offer_warehouse_and_state_filters(): void
    {
        $this->actingAs($this->admin);
        $index = route('warehouses.load-checks.index', $this->warehouse);
        $detail = route('warehouses.load-checks.show', [$this->warehouse, $this->row->id]);
        $this->get($index)->assertOk()->assertSee('2 ellenőrzésre vár')->assertSee('Megmaradt tétel')
            ->assertSee('Második termék')->assertSee($detail)->assertSee('Másik raktár');
        $this->get($index.'?q=SZ-01+MERES-01')->assertOk()->assertSee('Megmaradt tétel')->assertDontSee('Második termék');
        $this->get($index.'?q=%25')->assertOk()->assertSee('A megadott feltételekkel nincs ellenőrizendő rekesztétel.');
        $this->get($index.'?warehouse_id='.$this->other->getKey().'&state=pending')
            ->assertRedirect(route('warehouses.load-checks.index', [$this->other, 'state' => 'pending']));
        $this->get(route('warehouses.load-checks.index', $this->other))->assertOk()
            ->assertSee('Ebben a raktárban jelenleg nincs ellenőrzésre váró rekeszterhelés.');
        $this->get(route('warehouses.show', $this->warehouse))->assertOk()->assertSee('2 ellenőrzésre vár');
        $this->get(route('warehouses.locations.show', [$this->warehouse, $this->location]))->assertOk()
            ->assertSee('Terhelés ellenőrzése')->assertSee($detail);
        $this->get($detail)->assertOk()->assertSee('Megjelenítés az alaprajzon')->assertViewHas('worksheet', fn ($sheet) =>
            $sheet['placement']['quantity'] === 6 && $sheet['placement']['recorded_weight_grams'] === 10000)
            ->assertDontSee('name="quantity"', escape: false);

        $this->submit($this->admin, $this->row, 15);
        $this->get($index.'?state=discrepancy_open')->assertOk()->assertSee('Megmaradt tétel')->assertDontSee('Második termék')
            ->assertSee('Kritikus terhelési eltérés')->assertSee('Rekeszterhelés túllépve')->assertDontSee('+3 kg');
        $this->get($index)->assertOk()->assertSeeInOrder(['Kritikus terhelési eltérés', 'Ellenőrzésre vár']);
        $this->get($index.'?state=pending')->assertOk()->assertDontSee('Megmaradt tétel')->assertSee('Második termék');
        $this->getJson(route('warehouse-load-verifications.show', [$this->warehouse, $this->row->id]))
            ->assertJsonPath('capacity.scopes.location_grams.free', 0)->assertJsonPath('capacity.scopes.location_grams.excess', 3000);
    }

    public function test_worksheet_permissions_preview_actual_measurement_and_closed_history(): void
    {
        $detail = route('warehouses.load-checks.show', [$this->warehouse, $this->row->id]);
        $index = route('warehouses.load-checks.index', $this->warehouse);
        $operator = $this->member('dispatch_operator', $this->warehouse);
        $supervisor = $this->member('supervisor', $this->warehouse);
        $foreign = $this->member('supervisor', $this->other);
        $preview = route('warehouse-load-verifications.preview', [$this->warehouse, $this->row->id]);
        $save = route('warehouse-load-verifications.store', [$this->warehouse, $this->row->id]);
        $data = ['revision' => app(WarehouseLoadVerificationService::class)->show($operator, $this->warehouse, $this->row->id)['placement']['revision'],
            'cargo_weight_kg' => '6.25', 'pallet_slots' => 0, 'note' => 'Mérleggel ellenőrizve',
            'measurement_confirmed' => true, 'operation_key' => (string) Str::uuid()];
        $this->actingAs($operator)->get($detail)->assertOk()->assertDontSee('Helyszíni mérés megerősítése');
        $this->get($index)->assertOk()->assertDontSee('Másik raktár');
        $this->get($index.'?warehouse_id='.$this->other->getKey())->assertForbidden();
        $this->postJson($preview, $data)->assertOk()->assertJsonPath('measurement.weight_grams', 6250)
            ->assertJsonPath('measurement.weight_difference_grams', -3750)->assertJsonPath('measurement.pallet_slots', 0)
            ->assertJsonPath('can_confirm', false);
        $this->postJson($save, $data)->assertForbidden();
        $this->actingAs($foreign)->get($detail)->assertForbidden();
        $this->get($index.'?state=history')->assertForbidden();
        $this->actingAs($supervisor)->get($detail)->assertOk()->assertSee('Helyszíni mérés megerősítése');
        $this->postJson($save, ['pallet_slots' => null] + $data)->assertUnprocessable()->assertJsonMissingPath('verification')
            ->assertJsonValidationErrors('pallet_slots');
        $this->postJson($save, $data)->assertCreated()->assertJsonPath('verification.quantity', 6);
        $this->postJson($save, $data)->assertCreated();
        $this->assertDatabaseCount('warehouse_load_verifications', 1);
        $this->assertSame(6, $this->row->fresh()->quantity);
        $this->assertSame(2000, $this->neighbour->fresh()->cargo_weight_grams);
        $this->get($index.'?state=history&placement_id='.$this->row->id)->assertOk()
            ->assertSee('Mérleggel ellenőrizve')->assertSee('Ellenőrzött')->assertSee('6,25 kg')->assertSee('SZ-01');
    }

    private function place(Product $product, int $quantity, int $grams): WarehousePlacement
    {
        $stock = Stock::create(['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity]);

        return $this->location->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $grams,
            'pallet_slots' => 0, 'load_requires_verification' => true]);
    }

    private function member(string $role, Warehouse $warehouse): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function submit(User $actor, WarehousePlacement $placement, int $weight): void
    {
        $data = app(WarehouseLoadVerificationService::class)->show($actor, $this->warehouse, $placement->id);
        $this->postJson(route('warehouse-load-verifications.store', [$this->warehouse, $placement->id]), [
            'revision' => $data['placement']['revision'], 'cargo_weight_kg' => $weight, 'pallet_slots' => 0,
            'measurement_confirmed' => true, 'operation_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('discrepancy_open', true);
    }
}
