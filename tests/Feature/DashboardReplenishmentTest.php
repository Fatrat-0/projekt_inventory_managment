<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Queries\ReplenishmentSuggestions;
use App\Support\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardReplenishmentTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Product $product;
    private Stock $source;
    private Stock $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->actor);
        $category = Category::create(['category_name' => 'Pótlás', 'default_item_min_stock' => 999]);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'UT-001', 'price' => 100, 'category_id' => $category->category_id, 'min_stock_override' => 999]);
        $this->source = $this->stock('Forrás', 20, 5, 5);
        $this->target = $this->stock('Cél', 4, 1, 10);
    }

    private function stock(string $name, int $quantity, int $quarantined, int $minimum): Stock
    {
        $warehouse = Warehouse::create(['warehouse_name' => $name]);
        return Stock::create(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $this->product->product_id,
            'quantity' => $quantity, 'quarantined_quantity' => $quarantined, 'min_quantity' => $minimum]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['request_key' => (string)Str::uuid(), 'product_id' => $this->product->product_id,
            'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id,
            'quantity' => 7, 'replenishment_stock_id' => $this->target->id], $changes);
    }

    private function incoming(int $quantity, string $status = 'pending'): void
    {
        InventoryTransfer::create(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id,
            'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => $quantity, 'status' => $status, 'user_id' => $this->actor->id]);
    }

    public function test_local_shortage_excludes_quarantine_and_counts_only_pending_inbound_without_using_global_minima(): void
    {
        $this->incoming(2); $this->incoming(100, 'completed'); $this->incoming(100, 'cancelled');
        $row = ReplenishmentSuggestions::query()->sole();
        $this->assertSame($this->target->id, (int)$row->id);
        $this->assertSame(3, (int)$row->available);
        $this->assertSame(2, (int)$row->incoming);
        $this->assertSame(5, (int)$row->shortage);
        $this->assertSame(10, (int)$row->surplus);
        $this->assertSame($this->source->id, (int)$row->source_stock_id);
        $this->get('/dashboard')->assertOk()->assertSee('Utánpótlási javaslatok')->assertSee('Raktárankénti készlethelyzet');
    }

    public function test_zero_minimum_exact_threshold_and_sufficient_inbound_do_not_generate_suggestions(): void
    {
        $this->target->update(['min_quantity' => 0]);
        $this->assertSame(0, ReplenishmentSuggestions::query()->count());
        $this->target->update(['min_quantity' => 3]);
        $this->assertSame(0, ReplenishmentSuggestions::query()->count());
        $this->target->update(['min_quantity' => 10]); $this->incoming(7);
        $this->assertSame(0, ReplenishmentSuggestions::query()->count());
        $this->incoming(2147483647);
        $this->assertSame(0, ReplenishmentSuggestions::query()->count());
    }

    public function test_best_source_is_deterministic_and_partial_or_external_supply_is_honest(): void
    {
        $this->source->update(['quantity' => 12]); // available 7, surplus 2
        $tied = $this->stock('Másik forrás', 7, 0, 5);
        $row = ReplenishmentSuggestions::query()->where('needs.id', $this->target->id)->sole();
        $this->assertSame($this->source->id, (int)$row->source_stock_id);
        $this->get('/dashboard')->assertOk()->assertSee('részleges pótlás');
        $this->source->update(['min_quantity' => 7]); $tied->update(['min_quantity' => 7]);
        $row = ReplenishmentSuggestions::query()->where('needs.id', $this->target->id)->sole();
        $this->assertNull($row->source_stock_id);
        $this->get('/dashboard')->assertOk()->assertSee('Külső pótlás szükséges.')->assertDontSee('data-replenishment-prepare', false);
    }

    public function test_prepare_is_read_only_and_prefills_the_existing_transfer_form(): void
    {
        $before = Stock::orderBy('id')->get()->toArray();
        $this->get(route('transfers.create', ['replenishment_stock_id' => $this->target->id, 'source_stock_id' => $this->source->id]))
            ->assertOk()->assertViewHas('prefill', ['replenishment_stock_id' => $this->target->id, 'product_id' => $this->product->product_id,
                'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => 7]);
        $this->assertSame($before, Stock::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->source->update(['quantity' => 10]);
        $this->get(route('transfers.create', ['replenishment_stock_id' => $this->target->id, 'source_stock_id' => $this->source->id]))
            ->assertRedirect(route('dashboard').'#replenishment-title')->assertSessionHasErrors('replenishment');
    }

    public function test_start_rechecks_supply_and_demand_and_replays_the_same_request_without_reapplying(): void
    {
        $payload = $this->payload();
        $this->post(route('transfers.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(13, $this->source->fresh()->quantity);
        $this->assertSame(4, $this->target->fresh()->quantity);
        $this->post(route('transfers.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->postJson(route('transfers.store'), $this->payload(['quantity' => 1]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('inventory_transfers', 1);
    }

    public function test_changed_source_minimum_or_quarantine_cannot_be_consumed_by_a_stale_suggestion(): void
    {
        $this->source->update(['min_quantity' => 12]);
        $this->postJson(route('transfers.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->source->update(['min_quantity' => 5, 'quarantined_quantity' => 15]);
        $this->postJson(route('transfers.store'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        $this->assertSame(20, $this->source->fresh()->quantity);
    }

    public function test_changed_demand_rejects_excess_but_allows_a_smaller_quantity(): void
    {
        $this->incoming(5);
        $this->postJson(route('transfers.store'), $this->payload())->assertUnprocessable();
        $this->post(route('transfers.store'), $this->payload(['quantity' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(18, $this->source->fresh()->quantity);
    }

    public function test_mismatched_target_and_changed_replay_mode_are_rejected_while_normal_transfers_remain_available(): void
    {
        $this->postJson(route('transfers.store'), $this->payload(['replenishment_stock_id' => $this->source->id]))->assertUnprocessable();
        $payload = $this->payload(['quantity' => 7]);
        $this->post(route('transfers.store'), $payload)->assertRedirect();
        unset($payload['replenishment_stock_id']);
        $this->postJson(route('transfers.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $payload['request_key'] = (string)Str::uuid();
        $this->post(route('transfers.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(6, $this->source->fresh()->quantity); // normal transfer may go below local minimum
    }

    public function test_warehouse_chart_includes_empty_warehouses_and_uses_one_global_scale_and_filtered_links_for_workers(): void
    {
        $empty = Warehouse::create(['warehouse_name' => 'Üres']);
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $response = $this->get('/dashboard')->assertOk();
        $rows = $response->viewData('warehouseStocks')->keyBy('warehouse_id');
        $this->assertSame(20, $response->viewData('warehousePeak'));
        $this->assertSame(15, (int)$rows[$this->source->warehouse_id]->available);
        $this->assertSame(0, (int)$rows[$empty->warehouse_id]->physical);
        $response->assertSee(route('stock.index', ['warehouse_id' => $empty->warehouse_id]), false);
        $this->get(route('stock.index', ['warehouse_id' => $empty->warehouse_id]))->assertOk()->assertSee('Nincs a feltételeknek megfelelő készletsor.');
        $this->get(route('stock.index', ['warehouse_id' => $this->target->warehouse_id]))->assertOk()->assertViewHas('stocks', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $this->target->id);
    }

    public function test_local_minimum_updates_only_the_setting_with_conflict_and_role_protection(): void
    {
        $url = route('stock.minimum', $this->target);
        $this->patch($url, ['min_quantity' => 20, 'expected_minimum' => 10])->assertRedirect();
        $this->assertSame(20, $this->target->fresh()->min_quantity);
        $this->assertSame(4, $this->target->fresh()->quantity);
        $this->patchJson($url, ['min_quantity' => 1, 'expected_minimum' => 10])->assertUnprocessable();
        foreach ([-1, 2147483648, ['bad'], 'bad'] as $value) $this->patchJson($url, ['min_quantity' => $value, 'expected_minimum' => 20])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->patch($url, ['min_quantity' => 1, 'expected_minimum' => 20])->assertForbidden();
        $this->get('/stock')->assertOk()->assertDontSee('name="expected_minimum"', false);
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->patch($url, ['min_quantity' => 0, 'expected_minimum' => 20])->assertRedirect();
        $this->assertSame(0, $this->target->fresh()->min_quantity);
    }

    public function test_new_panels_are_added_to_existing_preferences_and_both_lists_have_independent_paging(): void
    {
        $legacy = array_diff(array_keys(DashboardLayout::PANELS), ['warehouse_stocks', 'replenishment']);
        $this->actor->forceFill(['dashboard_layout' => ['order' => array_values($legacy), 'visible' => []]])->save();
        for ($i = 0; $i < 27; $i++) $this->stock('Hiány '.$i, 0, 0, 1);
        $response = $this->get('/dashboard?warehouse_page=2&replenishment_page=2&days=30&chart=line')->assertOk();
        $this->assertSame(['warehouse_stocks', 'replenishment'], $response->viewData('dashboardLayout')['visible']);
        $this->assertSame(2, $response->viewData('warehouseStocks')->currentPage());
        $this->assertSame(2, $response->viewData('replenishments')->currentPage());
        $this->assertSame(20, $response->viewData('warehousePeak'));
        $this->assertSame(28, $response->viewData('replenishmentCount'));
        $this->get('/dashboard?warehouse_page=999&replenishment_page=999')->assertRedirect();
    }
}
