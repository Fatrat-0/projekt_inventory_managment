<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, ReturnQualityAction, Stock, User, Warehouse};
use App\Services\InventoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnQualityListingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Category $category;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->user);
        $this->category = Category::create(['category_name' => 'Minősítési lista', 'default_item_min_stock' => 5]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Helyi minősítő raktár', 'location' => 'Budapest']);
    }

    private function product(string $sku, int $physical = 0, int $blocked = 0, ?Category $category = null, int $price = 100, bool $stockRow = true): Product
    {
        $product = Product::create(['product_name' => 'Termék '.$sku, 'sku' => $sku, 'price' => $price,
            'category_id' => ($category ?? $this->category)->category_id]);
        if ($stockRow) Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'quantity' => $physical, 'quarantined_quantity' => $blocked]);
        return $product;
    }

    private function returned(Product $product, int $quantity, string $reason = 'Vevői visszáru vizsgálatra'): InventoryMovement
    {
        $inventory = app(InventoryService::class);
        $source = $inventory->recordMovement(['product_id' => $product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'out', 'quantity' => $quantity]);
        return $inventory->recordReturn($source, ['user_id' => $this->user->id, 'quantity' => $quantity, 'return_reason' => $reason]);
    }

    private function process(InventoryMovement $return, string $action, int $quantity, string $reason = 'Minősítés elvégezve'): ReturnQualityAction
    {
        return app(InventoryService::class)->processReturnQuality($return,
            ['user_id' => $this->user->id, 'action' => $action, 'quantity' => $quantity, 'reason' => $reason]);
    }

    private function statusProducts(): array
    {
        $blocked = $this->product('BLOCKED', 20, 20, price: 10);
        $low = $this->product('LOW', 8, 7, price: 1);
        $other = Warehouse::create(['warehouse_name' => 'Második raktár', 'location' => 'Pécs']);
        Stock::create(['product_id' => $low->product_id, 'warehouse_id' => $other->warehouse_id, 'quantity' => 4, 'quarantined_quantity' => 2]);
        $ok = $this->product('OK', 9, 4, price: 2);
        $empty = $this->product('NO-ROW', stockRow: false);
        return compact('blocked', 'low', 'ok', 'empty');
    }

    public function test_product_status_uses_available_global_stock_including_missing_rows_and_exact_threshold(): void
    {
        $products = $this->statusProducts();
        foreach (['low' => ['blocked', 'low', 'empty'], 'out' => ['blocked', 'empty'], 'ok' => ['ok']] as $status => $keys) {
            $response = $this->get(route('products.index', ['stock_status' => $status]))->assertOk();
            $this->assertEqualsCanonicalizing(array_map(fn($key) => $products[$key]->product_id, $keys),
                $response->viewData('products')->pluck('product_id')->all());
        }
        $listed = $this->get(route('products.index'))->assertOk()->viewData('products')->keyBy('product_id');
        $low = $listed[$products['low']->product_id];
        $this->assertSame([12, 9, 3], array_map('intval', [$low->total_stock, $low->total_quarantined, $low->total_available]));
        $empty = $listed[$products['empty']->product_id];
        $this->assertSame(0, (int)$empty->total_available);
        $this->assertSame(0, (int)$empty->total_stock);
    }

    public function test_stock_and_value_sorting_keep_physical_quantities_when_available_order_differs(): void
    {
        $products = $this->statusProducts();
        foreach (['total_stock' => ['empty', 'ok', 'low', 'blocked'], 'stock_value' => ['empty', 'low', 'ok', 'blocked']] as $sort => $keys) {
            $listed = $this->get(route('products.index', ['sort_by' => $sort, 'sort_dir' => 'asc']))->assertOk()->viewData('products');
            $this->assertSame(array_map(fn($key) => $products[$key]->product_id, $keys), $listed->pluck('product_id')->all());
            $this->assertSame(20, (int)$listed->last()->total_stock);
            $this->assertSame(0, (int)$listed->last()->total_available);
        }
    }

    public function test_fully_blocked_stock_without_a_minimum_is_out_but_is_neither_low_nor_ok(): void
    {
        $category = Category::create(['category_name' => 'Minimum nélküli kategória']);
        $product = $this->product('NO-MINIMUM', 7, 7, $category);
        $this->assertSame([$product->product_id], $this->get(route('products.index', ['stock_status' => 'out']))
            ->assertOk()->viewData('products')->pluck('product_id')->all());
        foreach (['low', 'ok'] as $status) {
            $this->assertSame(0, $this->get(route('products.index', ['stock_status' => $status]))->assertOk()->viewData('products')->total());
        }
    }

    public function test_dashboard_product_and_category_alerts_clear_when_available_stock_reaches_the_minimum(): void
    {
        $category = Category::create(['category_name' => 'Vizsgálat alatt álló kategória', 'default_item_min_stock' => 5, 'aggregate_min_stock' => 5]);
        $product = $this->product('DASHBOARD-RETURN', 10, category: $category);
        $return = $this->returned($product, 8);
        $healthy = Category::create(['category_name' => 'Pontosan megfelelő kategória', 'default_item_min_stock' => 5, 'aggregate_min_stock' => 5]);
        $this->product('EXACT-MINIMUM', 10, 5, $healthy);
        $response = $this->get(route('dashboard'))->assertOk()->assertSee('Kiadható: 2 db')->assertSee('Elkülönítve: 8 db');
        $this->assertSame([$product->product_id], $response->viewData('lowStockProducts')->pluck('product_id')->values()->all());
        $this->assertSame([$category->category_id], $response->viewData('lowStockCategories')->pluck('category_id')->values()->all());
        $this->process($return, 'release', 3);
        $response = $this->get(route('dashboard'))->assertOk();
        $this->assertCount(0, $response->viewData('lowStockProducts'));
        $this->assertCount(0, $response->viewData('lowStockCategories'));
        $this->assertDatabaseHas('stocks', ['product_id' => $product->product_id, 'quantity' => 10, 'quarantined_quantity' => 5]);
    }

    public function test_local_quarantine_filter_and_warehouse_totals_do_not_include_other_warehouses(): void
    {
        $blocked = $this->product('LOCAL-BLOCKED', 8, 8);
        $mixed = $this->product('LOCAL-MIXED', 6, 1);
        $plain = $this->product('LOCAL-PLAIN', 4);
        $this->product('LOCAL-ZERO');
        $other = Warehouse::create(['warehouse_name' => 'Másik elkülönítő raktár', 'location' => 'Győr']);
        Stock::create(['product_id' => $plain->product_id, 'warehouse_id' => $other->warehouse_id, 'quantity' => 777, 'quarantined_quantity' => 777]);
        $params = ['warehouse' => $this->warehouse, 'stock' => 'quarantined'];
        $response = $this->get(route('warehouses.show', $params))->assertOk();
        $this->assertEqualsCanonicalizing([$blocked->product_id, $mixed->product_id], $response->viewData('stocks')->pluck('product_id')->all());
        $totals = $response->viewData('stockTotals');
        $this->assertSame([18, 9, 9], array_map('intval', [$totals->quantity, $totals->total_quarantined, $totals->total_available]));
        $warehouse = $this->get(route('warehouses.index'))->assertOk()->viewData('warehouses')->firstWhere('warehouse_id', $this->warehouse->warehouse_id);
        $this->assertSame([18, 9, 9], array_map('intval', [$warehouse->total_quantity, $warehouse->total_quarantined, $warehouse->total_available]));
        Pdf::shouldReceive('loadView')->once()->withArgs(function($view, $data) use ($blocked, $mixed) {
            $this->assertSame('pdf.warehouse-inventory', $view);
            $this->assertEqualsCanonicalizing([$blocked->product_id, $mixed->product_id], $data['stocks']->pluck('product_id')->all());
            $this->assertTrue($data['partial']);
            $this->assertSame(9, (int)$data['stocks']->sum('quarantined_quantity'));
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('warehouses.inventory-pdf', $params))->assertOk();
    }

    public static function inventoryPdfModes(): array
    {
        return [
            'global visible' => ['global', false, true], 'global blind' => ['global', true, true],
            'global without warehouse column' => ['global', false, false], 'global blind without warehouse column' => ['global', true, false],
            'local visible' => ['local', false, true], 'local blind' => ['local', true, true],
        ];
    }

    #[DataProvider('inventoryPdfModes')]
    public function test_inventory_exports_show_physical_and_quality_breakdown_only_outside_blind_mode(string $scope, bool $blind, bool $showWarehouses): void
    {
        $product = $this->product('PDF-QUALITY', 98731, price: 91317);
        $returnReason = 'SECRET-RETURN-REASON-DO-NOT-PRINT';
        $qualityReason = 'SECRET-QUALITY-REASON-DO-NOT-PRINT';
        $return = $this->returned($product, 23457, $returnReason);
        $this->process($return, 'release', 2, $qualityReason);
        $stock = $product->stocks()->sole();
        $before = $stock->getAttributes();
        $amounts = [$stock->quantity, $stock->quarantined_quantity, $stock->available_quantity];
        Pdf::shouldReceive('loadView')->once()->withArgs(function($view, $data) use ($scope, $blind, $showWarehouses, $returnReason, $qualityReason, $amounts) {
            $this->assertSame($scope === 'local' ? 'pdf.warehouse-inventory' : 'pdf.inventory', $view);
            $this->assertSame($blind, $data['options']['blind_mode']);
            if ($scope === 'global') $this->assertSame($showWarehouses, $data['options']['show_warehouses']);
            if ($scope === 'local' && $blind) $this->assertTrue($data['options']['show_count_column']);
            $html = view($view, $data)->render();
            $this->assertStringNotContainsString($returnReason, $html);
            $this->assertStringNotContainsString($qualityReason, $html);
            foreach (['Kiadható:', 'Elkülönítve:'] as $label) {
                if ($blind) $this->assertStringNotContainsString($label, $html);
                else $this->assertStringContainsString($label, $html);
            }
            foreach ($amounts as $quantity) {
                $raw = (string)$quantity;
                $formatted = number_format($quantity, 0, ',', ' ');
                if ($blind) {
                    $this->assertStringNotContainsString($raw, $html);
                    $this->assertStringNotContainsString($formatted, $html);
                } else {
                    $this->assertTrue(str_contains($html, $raw) || str_contains($html, $formatted), 'Missing inventory quantity: '.$quantity);
                }
            }
            if ($blind) {
                $this->assertStringNotContainsString('91 317', $html);
                $this->assertStringNotContainsString('91317', $html);
                $this->assertStringContainsString('VAKLELTÁR', $html);
            }
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        Pdf::shouldReceive('stream')->once()->andReturn(response('pdf'));
        $params = ['preview' => 1, 'orientation' => 'landscape', 'show_filters' => 1, 'blind_mode' => (int)$blind];
        if ($scope === 'local') {
            $url = route('warehouses.inventory-pdf', ['warehouse' => $this->warehouse, 'stock' => 'all', 'show_count_column' => 0] + $params);
        } else {
            $url = route('export.inventory', ['show_warehouses' => (int)$showWarehouses, 'show_prices' => 1, 'show_count_col' => 0] + $params);
        }
        $this->get($url)->assertOk();
        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('return_quality_actions', 1);
    }

    public static function finalQualityActions(): array { return [['release'], ['scrap']]; }

    #[DataProvider('finalQualityActions')]
    public function test_pending_quality_filter_keeps_partial_returns_and_removes_only_fully_processed_returns(string $finalAction): void
    {
        $product = $this->product('PENDING-QUALITY', 10);
        $first = $this->returned($product, 5);
        $second = $this->returned($product, 2);
        $this->process($first, 'release', 2);
        $pending = fn() => $this->get(route('movements.index', ['kind' => 'quarantined']))->assertOk()->viewData('movements')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $pending());
        $this->process($first, $finalAction, 3);
        $this->assertSame([$second->id], $pending());
        $this->assertSame(5, $first->fresh()->quarantined_quantity);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->get(route('movements.index', ['kind' => 'return']))
            ->assertOk()->viewData('movements')->pluck('id')->all());
        $this->process($second, 'release', 2);
        $this->assertSame([], $pending());
    }

    public function test_scrap_filter_and_literal_reason_search_match_export_without_polluting_regular_movements(): void
    {
        $product = $this->product('SEARCH-QUALITY', 10);
        $return = $this->returned($product, 5);
        $reason = 'Sérült <b>burkolat</b> 100% A_B';
        $scrap = $this->process($return, 'scrap', 2, $reason);
        $this->process($return, 'scrap', 1, 'Más selejtezési indok 1000 AXB');
        $params = ['kind' => 'scrap', 'search' => 'Sérült 100% A_B'];
        $response = $this->get(route('movements.index', $params))->assertOk();
        $this->assertSame([(int)$scrap->movement_id], $response->viewData('movements')->pluck('id')->map(fn($id) => (int)$id)->all());
        $this->assertSame(2, $response->viewData('totalOut'));
        $this->assertSame(0, $response->viewData('totalIn'));
        $response->assertSee($reason)->assertDontSee($reason, false);
        $regular = $this->get(route('movements.index', ['kind' => 'regular']))->assertOk()->viewData('movements');
        $this->assertSame([(int)$return->return_of_id], $regular->pluck('id')->map(fn($id) => (int)$id)->all());
        Pdf::shouldReceive('loadView')->once()->withArgs(function($view, $data) use ($scrap, $reason) {
            $this->assertSame('pdf.movements', $view);
            $this->assertSame([(int)$scrap->movement_id], $data['movements']->pluck('id')->map(fn($id) => (int)$id)->all());
            $html = view($view, $data)->render();
            $this->assertStringContainsString(e($reason), $html);
            $this->assertStringNotContainsString($reason, $html);
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('export.movements', $params))->assertOk();
    }
}

