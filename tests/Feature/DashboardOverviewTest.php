<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Queries\ProductListing;
use App\Services\{InventoryService, ShipmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Category $category;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        $this->actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->actor);
        $this->category = Category::create(['category_name' => 'Teszt']);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Fő raktár', 'location' => 'Budapest']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_replace(['category_id' => $this->category->category_id, 'product_name' => 'Termék',
            'sku' => 'DASH-'.Product::count(), 'price' => 100], $attributes));
    }

    private function stock(Product $product, int $quantity, int $quarantined = 0, ?Warehouse $warehouse = null): Stock
    {
        return Stock::create(['product_id' => $product->product_id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->warehouse_id,
            'quantity' => $quantity, 'quarantined_quantity' => $quarantined]);
    }

    private function movement(Product $product, string $type, int $quantity, string $date, array $attributes = []): InventoryMovement
    {
        return InventoryMovement::forceCreate(array_replace(['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->actor->id, 'type' => $type, 'quantity' => $quantity, 'created_at' => $date, 'updated_at' => $date], $attributes));
    }

    public function test_empty_database_has_honest_zero_states_and_complete_daily_series(): void
    {
        $response = $this->get('/dashboard')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['physical' => 0, 'quarantined' => 0, 'available' => 0], $response->viewData('stockSummary'));
        $this->assertSame(['count' => 0, 'quantity' => 0], $response->viewData('transitSummary'));
        $this->assertSame(0, $response->viewData('lowStockProductCount'));
        $this->assertSame(0, $response->viewData('lowStockCategoryCount'));
        $this->assertCount(7, $response->viewData('dailyActivity'));
        $this->assertSame(1, $response->viewData('activityPeak'));
        $response->assertSee('Nincs beállított minimum alatti termék.')->assertSee('Ebben az időszakban nem történt készletmozgás.')
            ->assertDontSee('Minden egyedi termékből megfelelő');
    }

    public function test_product_alerts_use_global_available_stock_override_zero_and_exact_boundaries(): void
    {
        $this->category->update(['default_item_min_stock' => 5]);
        $missing = $this->product();
        $quarantined = $this->product(); $this->stock($quarantined, 10, 8);
        $exact = $this->product(); $this->stock($exact, 5);
        $zeroOverride = $this->product(['min_stock_override' => 0]);
        $ownMinimum = $this->product(['min_stock_override' => 12]); $this->stock($ownMinimum, 10);
        $noMinimum = $this->product(['category_id' => Category::create(['category_name' => 'Nincs minimum'])->category_id]);
        $twoWarehouses = $this->product(); $this->stock($twoWarehouses, 2);
        $this->stock($twoWarehouses, 3, 0, Warehouse::create(['warehouse_name' => 'Másik']));
        $response = $this->get('/dashboard')->assertOk()->assertSee('Kiadható: 2 db')->assertSee('Elkülönítve: 8 db');
        $this->assertSame([$missing->product_id, $quarantined->product_id, $ownMinimum->product_id], $response->viewData('lowStockProducts')->pluck('product_id')->all());
        $this->assertSame(3, $response->viewData('lowStockProductCount'));
        $listing = new ProductListing(Request::create('/products', 'GET', ['stock_status' => 'low']));
        $this->assertEqualsCanonicalizing($response->viewData('lowStockProducts')->pluck('product_id')->all(), $listing->query()->pluck('products.product_id')->all());
        $this->assertSame(22, $response->viewData('stockSummary')['available']);
    }

    public function test_category_minimum_uses_direct_products_only_and_empty_categories_are_checked(): void
    {
        $this->category->update(['aggregate_min_stock' => 10]);
        $child = Category::create(['category_name' => 'Gyermek', 'parent_id' => $this->category->category_id, 'aggregate_min_stock' => 5]);
        $this->stock($this->product(), 5, 3);
        $this->stock($this->product(['category_id' => $child->category_id]), 100);
        $empty = Category::create(['category_name' => 'Üres', 'aggregate_min_stock' => 4]);
        Category::create(['category_name' => 'Nulla', 'aggregate_min_stock' => 0]);
        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame([$this->category->category_id, $empty->category_id], $response->viewData('lowStockCategories')->pluck('category_id')->all());
        $this->assertSame(2, (int)$response->viewData('lowStockCategories')->first()->total_available);
        $response->assertSee('az alkategóriák nélkül');
    }

    public function test_alert_previews_are_bounded_but_counts_are_complete_and_ordering_is_stable(): void
    {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $category = Category::create(['category_name' => 'Hiány '.$i, 'aggregate_min_stock' => 10]);
            $ids[] = $this->product(['category_id' => $category->category_id, 'min_stock_override' => 10])->product_id;
        }
        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame(8, $response->viewData('lowStockProductCount'));
        $this->assertSame(8, $response->viewData('lowStockCategoryCount'));
        $this->assertSame($ids, $response->viewData('lowStockProducts')->pluck('product_id')->all());
        $this->assertCount(8, $response->viewData('lowStockCategories'));
        $response->assertSee(route('products.index', ['stock_status' => 'low']));
    }

    public function test_activity_includes_the_start_boundary_and_zero_days_but_excludes_future_entries(): void
    {
        $product = $this->product();
        $this->movement($product, 'in', 99, '2026-10-28 23:59:59');
        $this->movement($product, 'in', 4, '2026-10-29 00:00:00');
        $today = $this->movement($product, 'out', 2, '2026-11-04 12:00:00');
        $this->movement($product, 'in', 88, '2026-11-04 12:00:01');
        $this->movement($product, 'out', 77, '2026-11-05 00:00:00');
        $response = $this->get('/dashboard')->assertOk();
        $daily = $response->viewData('dailyActivity');
        $this->assertSame('2026-10-29', $daily->first()['date']);
        $this->assertSame(1, $daily->first()['in_count']);
        $this->assertSame(0, $daily[1]['in_count'] + $daily[1]['out_count']);
        $this->assertSame(['count' => 2, 'in_quantity' => 4, 'out_quantity' => 2], $response->viewData('activitySummary'));
        $this->assertSame($today->id, $response->viewData('recentMovements')->first()->id);
        $response->assertSee(route('movements.index', ['date_from' => '2026-10-29', 'date_to' => '2026-11-04']));
        $this->assertSame(3, $this->get('/dashboard?days=30')->assertOk()->viewData('activitySummary')['count']);
    }

    public function test_period_changes_do_not_filter_live_tasks_and_recent_movement_ties_are_stable(): void
    {
        $product = $this->product(['min_stock_override' => 5]);
        $ids = [];
        for ($i = 0; $i < 8; $i++) $ids[] = $this->movement($product, 'in', 1, '2026-10-10 10:00:00')->id;
        $short = $this->get('/dashboard?days=7')->assertOk();
        $long = $this->get('/dashboard?days=30')->assertOk();
        $this->assertSame(0, $short->viewData('activitySummary')['count']);
        $this->assertSame(8, $long->viewData('activitySummary')['count']);
        $this->assertCount(30, $long->viewData('dailyActivity'));
        $this->assertSame(1, $short->viewData('lowStockProductCount'));
        $this->assertSame(1, $long->viewData('lowStockProductCount'));
        $this->assertSame(array_reverse($ids), $long->viewData('recentMovements')->pluck('id')->all());
    }

    public function test_transit_counts_stock_once_and_shipment_items_open_the_shipment_workflow(): void
    {
        $product = $this->product(); $this->stock($product, 20, 3);
        $target = Warehouse::create(['warehouse_name' => 'Cél']);
        $inventory = app(InventoryService::class);
        $data = ['product_id' => $product->product_id, 'from_warehouse_id' => $this->warehouse->warehouse_id, 'to_warehouse_id' => $target->warehouse_id, 'user_id' => $this->actor->id];
        $standalone = $inventory->startTransfer($data + ['quantity' => 4]);
        $shipmentData = ['reference' => 'DASHBOARD-SHIPMENT', 'origin_warehouse_id' => $this->warehouse->warehouse_id, 'stops' => [['warehouse_id' => $target->warehouse_id, 'items' => [['product_id' => $product->product_id, 'quantity' => 5]]]]];
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->save($shipmentData, $this->actor->id);
        $shipments->start($shipment, $this->actor->id, 1);
        $shipments->save($shipmentData, $this->actor->id);
        foreach ([true, false] as $complete) $inventory->finalizeTransfer($inventory->startTransfer($data + ['quantity' => 1]), $complete);
        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame(['physical' => 11, 'quarantined' => 3, 'available' => 8], $response->viewData('stockSummary'));
        $this->assertSame(['count' => 2, 'quantity' => 9], $response->viewData('transitSummary'));
        $this->assertSame(['in_transit' => 1, 'drafts' => 1], $response->viewData('shipmentSummary'));
        $response->assertSee(route('shipments.show', $shipment))->assertSee(route('transfers.show', $standalone));
        $this->assertSame(0, $response->viewData('activitySummary')['count']);
    }

    public function test_return_and_scrap_activity_is_named_correctly_without_counting_release_as_a_movement(): void
    {
        $product = $this->product(); $this->stock($product, 10);
        $inventory = app(InventoryService::class);
        $original = $inventory->recordMovement(['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->actor->id, 'type' => 'out', 'quantity' => 6]);
        $return = $inventory->recordReturn($original, ['user_id' => $this->actor->id, 'quantity' => 6, 'return_reason' => 'Ellenőrzés']);
        foreach (['release', 'scrap'] as $action) $inventory->processReturnQuality($return, ['user_id' => $this->actor->id, 'action' => $action, 'quantity' => 2, 'reason' => 'Vizsgálat']);
        $response = $this->get('/dashboard')->assertOk()->assertSee('Vevői visszáru')->assertSee('Selejtezés');
        $this->assertSame(['count' => 3, 'in_quantity' => 6, 'out_quantity' => 8], $response->viewData('activitySummary'));
        $this->assertSame(2, $response->viewData('qualitySummary')['quantity']);
    }

    public function test_no_available_stock_badge_distinguishes_quarantine_and_missing_stock_from_usable_stock(): void
    {
        $missing = $this->product(['min_stock_override' => 5]);
        $quarantined = $this->product(['min_stock_override' => 5]);
        $this->stock($quarantined, 10, 10);
        $available = $this->product(['min_stock_override' => 5]);
        $this->stock($available, 2, 2);
        $this->stock($available, 1, 0, Warehouse::create(['warehouse_name' => 'Másik']));
        $this->product(['min_stock_override' => 0]);
        $this->product(['min_stock_override' => null]);

        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame(3, $response->viewData('lowStockProductCount'));
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ([$missing, $quarantined] as $product) {
            $this->assertSame(1, $xpath->query("//li[@data-product-alert='{$product->product_id}']//*[@data-no-available-stock]")->length);
        }
        $this->assertSame(0, $xpath->query("//li[@data-product-alert='{$available->product_id}']//*[@data-no-available-stock]")->length);
        $this->assertSame(2, $xpath->query('//*[@data-no-available-stock]')->length);
    }

    public function test_waiting_labels_use_the_dashboard_snapshot_keep_exact_dates_and_handle_future_records(): void
    {
        $product = $this->product();
        $target = Warehouse::create(['warehouse_name' => 'Fogadó']);
        $transfer = InventoryTransfer::forceCreate(['product_id' => $product->product_id, 'from_warehouse_id' => $this->warehouse->warehouse_id,
            'to_warehouse_id' => $target->warehouse_id, 'user_id' => $this->actor->id, 'quantity' => 1, 'status' => 'pending',
            'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00']);
        $original = $this->movement($product, 'out', 5, '2026-11-01 10:00:00');
        $return = $this->movement($product, 'in', 5, '2026-11-04 09:30:00', ['return_of_id' => $original->id, 'quarantined_quantity' => 5]);

        $this->get('/dashboard')->assertOk()->assertSee('3 napja úton')->assertSee('2 órája minősítésre vár')
            ->assertSee('2026.11.01. 12:00')->assertSee('2026.11.04. 09:30')->assertDontSee('késés');

        $transfer->forceFill(['created_at' => '2026-11-05 12:00:00'])->save();
        $return->forceFill(['created_at' => '2026-11-05 12:00:00'])->save();
        $response = $this->get('/dashboard')->assertOk()->assertDontSee('-1 napja');
        $this->assertSame(2, substr_count($response->getContent(), 'Jövőbeli időpont – ellenőrzendő'));
    }

    public static function roles(): array { return [['admin', true], ['manager', true], ['worker', false]]; }

    #[DataProvider('roles')]
    public function test_role_specific_links_are_usable(string $role, bool $manage): void
    {
        $this->actor->update(['role' => $role]);
        $this->category->update(['aggregate_min_stock' => 5]);
        $response = $this->get('/dashboard')->assertOk()->assertSee(route('movements.create'));
        if ($manage) $response->assertSee(route('categories.edit', $this->category));
        else $response->assertDontSee(route('categories.edit', $this->category));
    }

    public function test_invalid_period_recovers_without_following_the_referrer_and_json_keeps_field_errors(): void
    {
        foreach (['bad', '0', '8', '2147483648', ['7']] as $days) {
            $this->from('/dashboard?days=bad')->get(route('dashboard', ['days' => $days]))->assertRedirect(route('dashboard'))->assertSessionHasErrors('days');
            $this->getJson(route('dashboard', ['days' => $days]))->assertUnprocessable()->assertJsonValidationErrors('days');
        }
        $this->get('/dashboard')->assertOk();
        auth()->logout();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboard_does_not_mutate_business_data_and_large_totals_do_not_overflow(): void
    {
        for ($i = 0; $i < 3; $i++) $this->stock($this->product(['min_stock_override' => 2147483647]), 2147483647, 2147483647);
        $snapshot = fn () => collect(['stocks', 'products', 'inventory_movements', 'inventory_transfers', 'return_quality_actions', 'shipments'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $before = $snapshot();
        $response = $this->get('/dashboard?days=30')->assertOk();
        $this->assertSame(6442450941, $response->viewData('stockSummary')['physical']);
        $this->assertSame(0, $response->viewData('stockSummary')['available']);
        $this->assertSame($before, $snapshot());
    }

    public static function chartPeriods(): array { return [[7], [30]]; }

    #[DataProvider('chartPeriods')]
    public function test_chart_selection_changes_only_presentation_and_line_points_are_finite(int $days): void
    {
        $product = $this->product();
        $this->movement($product, 'in', 20, '2026-11-04 11:00:00');
        $this->movement($product, 'out', 3, '2026-11-04 11:00:00');
        $bar = $this->get('/dashboard?days='.$days)->assertOk();
        $line = $this->get('/dashboard?days='.$days.'&chart=line')->assertOk()->assertViewHas('chartType', 'line');
        foreach (['activitySummary', 'stockSummary', 'transitSummary', 'shipmentSummary'] as $key) {
            $this->assertSame($bar->viewData($key), $line->viewData($key));
        }
        $this->assertSame($bar->viewData('dailyActivity')->toArray(), $line->viewData('dailyActivity')->toArray());
        $line->assertSee(route('dashboard', ['days' => $days, 'chart' => 'line']))->assertSee('Két külön vonal');
        foreach (['in', 'out'] as $series) {
            preg_match('/data-series="'.$series.'" points="([^"]+)"/', $line->getContent(), $matches);
            $this->assertCount(2, $matches);
            $points = preg_split('/\s+/', $matches[1]);
            $this->assertCount($days, $points);
            foreach ($points as $point) {
                [$x, $y] = array_map('floatval', explode(',', $point));
                $this->assertTrue(is_finite($x) && is_finite($y) && $x >= 0 && $x <= 700 && $y >= 0 && $y <= 160);
            }
        }
        $this->assertSame(2, $line->viewData('activitySummary')['count']);
    }

    public function test_empty_line_chart_has_valid_zero_baselines_and_no_business_side_effects(): void
    {
        $response = $this->get('/dashboard?chart=line')->assertOk()->assertSee('Ebben az időszakban nem történt készletmozgás.');
        preg_match_all('/data-series="(?:in|out)" points="([^"]+)"/', $response->getContent(), $series);
        $this->assertCount(2, $series[1]);
        $this->assertSame($series[1][0], $series[1][1]);
        foreach (explode(' ', $series[1][0]) as $point) $this->assertSame(155.0, (float)explode(',', $point)[1]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_invalid_chart_keeps_a_valid_period_and_invalid_period_keeps_a_valid_chart(): void
    {
        foreach (['pie', 'LINE', ['line'], '0'] as $chart) {
            $this->get(route('dashboard', ['days' => 30, 'chart' => $chart]))->assertRedirect(route('dashboard', ['days' => 30]))->assertSessionHasErrors('chart');
            $this->getJson(route('dashboard', ['chart' => $chart]))->assertUnprocessable()->assertJsonValidationErrors('chart');
        }
        $this->get('/dashboard?days=bad&chart=line')->assertRedirect(route('dashboard', ['chart' => 'line']))->assertSessionHasErrors('days');
        $this->get('/dashboard?chart=')->assertOk()->assertViewHas('chartType', 'bar');
    }

    public function test_alert_lists_page_independently_preserve_chart_settings_and_recover_from_deleted_pages(): void
    {
        $ids = [];
        $categoryIds = [];
        for ($i = 0; $i < 28; $i++) {
            $category = Category::create(['category_name' => 'Lapozás '.$i, 'aggregate_min_stock' => 10]);
            $categoryIds[] = $category->category_id;
            $ids[] = $this->product(['category_id' => $category->category_id, 'min_stock_override' => 10])->product_id;
        }
        $first = $this->get('/dashboard?days=30&chart=line')->assertOk();
        $this->assertCount(25, $first->viewData('lowStockProducts'));
        $this->assertCount(25, $first->viewData('lowStockCategories'));
        $this->assertSame(28, $first->viewData('lowStockProductCount'));
        $first->assertSee('Több megjelenítése')->assertSee('Kevesebb megjelenítése');
        $second = $this->get('/dashboard?days=30&chart=line&product_alert_page=2')->assertOk();
        $this->assertSame(array_slice($ids, 25), $second->viewData('lowStockProducts')->pluck('product_id')->all());
        $this->assertSame(array_slice($categoryIds, 0, 25), $second->viewData('lowStockCategories')->pluck('category_id')->all());
        $categoryNext = $second->viewData('lowStockCategories')->nextPageUrl();
        $this->assertStringContainsString('product_alert_page=2', $categoryNext);
        $this->assertStringContainsString('category_alert_page=2', $categoryNext);
        $this->assertStringContainsString('chart=line', $categoryNext);
        $this->assertStringContainsString('#category-alerts-title', $categoryNext);
        $this->get($categoryNext)->assertOk()->assertViewHas('lowStockCategories', fn ($page) => $page->pluck('category_id')->all() === array_slice($categoryIds, 25));
        Product::whereIn('product_id', array_slice($ids, 25))->update(['min_stock_override' => 0]);
        $recovered = $this->get('/dashboard?days=30&chart=line&product_alert_page=2&category_alert_page=2')->assertRedirect();
        $this->assertStringContainsString('product_alert_page=1', $recovered->headers->get('Location'));
        $this->assertStringContainsString('category_alert_page=2', $recovered->headers->get('Location'));
        $this->assertStringContainsString('#product-alerts-title', $recovered->headers->get('Location'));
        $this->get($recovered->headers->get('Location'))->assertOk();
    }

    public function test_alert_paging_rejects_malformed_values_and_omits_expansion_controls_for_short_lists(): void
    {
        foreach (['product_alert_page', 'category_alert_page', 'transfer_page', 'movement_page'] as $key) {
            foreach ([0, -1, 'bad', ['2'], 2147483648] as $page) {
                $this->get(route('dashboard', [$key => $page, 'days' => 30, 'chart' => 'line']))
                    ->assertRedirect(route('dashboard', ['days' => 30, 'chart' => 'line']))->assertSessionHasErrors($key);
                $this->getJson(route('dashboard', [$key => $page]))->assertUnprocessable()->assertJsonValidationErrors($key);
            }
        }
        for ($i = 0; $i < 3; $i++) $this->product(['min_stock_override' => 10]);
        $this->get('/dashboard')->assertOk()->assertDontSee('Több megjelenítése');
        $response = $this->get('/dashboard?product_alert_page=2147483647')->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_transfer_and_movement_lists_have_independent_pages_and_ignore_closed_or_future_records(): void
    {
        $product = $this->product();
        $this->stock($product, 40);
        $target = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $inventory = app(InventoryService::class);
        $transferData = ['product_id' => $product->product_id, 'from_warehouse_id' => $this->warehouse->warehouse_id,
            'to_warehouse_id' => $target->warehouse_id, 'quantity' => 1, 'user_id' => $this->actor->id];
        $movementData = ['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'type' => 'in', 'quantity' => 1, 'user_id' => $this->actor->id];
        $transfers = []; $movements = [];
        for ($i = 0; $i < 28; $i++) {
            $movements[] = $inventory->recordMovement($movementData)->id;
            $transfers[] = $inventory->startTransfer($transferData);
        }
        foreach ([true, false] as $complete) $inventory->finalizeTransfer($inventory->startTransfer($transferData), $complete);
        $this->movement($product, 'in', 100, '2026-11-05 00:00:00');
        $snapshot = fn () => DB::table('stocks')->orderBy('id')->get()->toJson();
        $before = $snapshot();
        $response = $this->get('/dashboard?days=30&chart=line&transfer_page=2')->assertOk();
        $this->assertSame(array_map(fn ($transfer) => $transfer->id, array_slice($transfers, 25)), $response->viewData('pendingTransfers')->pluck('id')->all());
        $this->assertSame(array_slice(array_reverse($movements), 0, 25), $response->viewData('recentMovements')->pluck('id')->all());
        $this->assertSame(28, $response->viewData('pendingTransfers')->total());
        $this->assertSame(28, $response->viewData('recentMovements')->total());
        $next = $response->viewData('recentMovements')->nextPageUrl();
        foreach (['transfer_page=2', 'movement_page=2', 'days=30', 'chart=line', '#recent-title'] as $part) $this->assertStringContainsString($part, $next);
        $second = $this->get($next)->assertOk();
        $this->assertSame(array_slice(array_reverse($movements), 25), $second->viewData('recentMovements')->pluck('id')->all());
        $this->assertSame($before, $snapshot());
        foreach (array_slice($transfers, 25) as $transfer) $inventory->finalizeTransfer($transfer, true);
        $recovered = $this->get('/dashboard?days=30&chart=line&transfer_page=2&movement_page=2')->assertRedirect();
        foreach (['transfer_page=1', 'movement_page=2', 'chart=line', '#pending-title'] as $part) $this->assertStringContainsString($part, $recovered->headers->get('Location'));
        $this->get($recovered->headers->get('Location'))->assertOk();
    }
}
