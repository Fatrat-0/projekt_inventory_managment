<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse};
use App\Queries\ProductListing;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    private function product(Category $category, string $name, string $sku, int $quantity = 0, ?Warehouse $warehouse = null): Product
    {
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => $name, 'sku' => $sku, 'price' => 100]);
        if ($warehouse) {
            Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => $quantity]);
        }
        return $product;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
    }

    public function test_combined_filters_include_deep_descendants_and_match_pdf(): void
    {
        $root = Category::create(['category_name' => 'Eszközök']);
        $child = Category::create(['category_name' => 'Számítógépek', 'parent_id' => $root->category_id]);
        $leaf = Category::create(['category_name' => 'Laptopok', 'parent_id' => $child->category_id, 'default_item_min_stock' => 10]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Budapest', 'location' => 'Budapest']);
        $wanted = $this->product($leaf, 'ThinkPad Pro', 'ABC-101', 3, $warehouse);
        $this->product($leaf, 'ThinkPad Pro', 'ABC-102', 20, $warehouse);
        $this->product($leaf, 'Más termék', 'OTHER', 3, $warehouse);
        $params = ['search' => 'ThinkPad ABC Laptopok', 'category_id' => $root->category_id, 'warehouse_id' => $warehouse->warehouse_id, 'stock_status' => 'low', 'sort_by' => 'stock_value', 'sort_dir' => 'asc'];
        $response = $this->get(route('products.index', $params))->assertOk();
        $this->assertSame([$wanted->product_id], $response->viewData('products')->pluck('product_id')->all());
        $this->assertCount(3, $response->viewData('categoryOptions'));
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) use ($wanted) {
            return $view === 'pdf.inventory' && $data['products']->pluck('product_id')->all() === [$wanted->product_id];
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('export.inventory', $params))->assertOk();
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        $wanted = $this->product($category, '100% pamut', 'A_B');
        $this->product($category, '1000 pamut', 'AXB');
        $response = $this->get(route('products.index', ['search' => '100% A_B']))->assertOk();
        $this->assertSame([$wanted->product_id], $response->viewData('products')->pluck('product_id')->all());
    }

    public function test_zero_stock_without_threshold_is_shown_as_out_of_stock(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        $empty = $this->product($category, 'Üres termék', 'EMPTY');
        $response = $this->get(route('products.index', ['stock_status' => 'out']))->assertOk()->assertSee('Kifogyott:');
        $this->assertSame([$empty->product_id], $response->viewData('products')->pluck('product_id')->all());
        $response = $this->get(route('products.index', ['stock_status' => 'ok']))->assertOk();
        $this->assertSame(0, $response->viewData('products')->total());
    }

    public function test_sorting_is_stable_and_pagination_keeps_filters_but_sort_links_reset_page(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->product($category, 'Azonos név', 'SKU-'.$i)->product_id;
        }
        $response = $this->get(route('products.index', ['search' => 'Azonos', 'sort_by' => 'price', 'sort_dir' => 'asc', 'page' => 2]))->assertOk();
        $products = $response->viewData('products');
        $this->assertSame(array_slice($ids, 10), $products->pluck('product_id')->all());
        $this->assertStringContainsString('search=Azonos', $products->url(1));
        preg_match_all('/href="([^"]*sort_by=[^"]*)"/', $response->getContent(), $links);
        $found = false;
        foreach ($links[1] as $link) {
            parse_str(parse_url(html_entity_decode($link), PHP_URL_QUERY) ?? '', $params);
            if (($params['sort_by'] ?? '') === 'sku') {
                $found = true;
                $this->assertArrayNotHasKey('page', $params);
            }
        }
        $this->assertTrue($found);
    }

    public function test_invalid_query_parameters_are_rejected_on_list_and_export(): void
    {
        foreach (['products.index', 'export.inventory'] as $route) {
            $this->getJson(route($route, ['per_page' => 0, 'sort_by' => 'invalid', 'search' => ['bad']]))
                ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'sort_by', 'search']);
        }
    }

    public function test_stock_value_sort_and_global_warehouse_semantics(): void
    {
        $category = Category::create(['category_name' => 'Teszt', 'default_item_min_stock' => 10]);
        $a = Warehouse::create(['warehouse_name' => 'A', 'location' => 'A']);
        $b = Warehouse::create(['warehouse_name' => 'B', 'location' => 'B']);
        $first = $this->product($category, 'Első', 'FIRST', 1, $a);
        Stock::create(['product_id' => $first->product_id, 'warehouse_id' => $b->warehouse_id, 'quantity' => 20]);
        $second = $this->product($category, 'Második', 'SECOND', 10, $a);
        $response = $this->get(route('products.index', ['warehouse_id' => $a->warehouse_id, 'stock_status' => 'ok', 'sort_by' => 'stock_value', 'sort_dir' => 'asc']))->assertOk();
        $products = $response->viewData('products');
        $this->assertSame([$second->product_id, $first->product_id], $products->pluck('product_id')->all());
        $this->assertEquals(21, $products->last()->total_stock);
    }
}
