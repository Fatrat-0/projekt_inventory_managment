<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductListBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
    }

    private function product(Category $category, string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $category->category_id, 'product_name' => 'Teszt termék',
            'sku' => $sku, 'price' => 100,
        ], $attributes));
    }

    private function stock(Product $product, Warehouse $warehouse, int $quantity): void
    {
        Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => $quantity]);
    }

    public static function pageSizes(): array
    {
        return [[10, 10], [25, 25], [50, 26], [100, 26]];
    }

    #[DataProvider('pageSizes')]
    public function test_page_size_limits_results_and_preserves_all_query_parameters(int $size, int $count): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        $warehouse = Warehouse::create(['warehouse_name' => 'A', 'location' => 'A']);
        for ($i = 1; $i <= 26; $i++) {
            $this->stock($this->product($category, sprintf('SKU-%02d', $i)), $warehouse, 1);
        }
        $params = ['search' => 'Teszt', 'category_id' => $category->category_id, 'warehouse_id' => $warehouse->warehouse_id,
            'stock_status' => 'ok', 'sort_by' => 'sku', 'sort_dir' => 'desc', 'per_page' => $size];
        $response = $this->get(route('products.index', $params))->assertOk();
        $products = $response->viewData('products');
        $this->assertCount($count, $products);
        $this->assertSame(26, $products->total());
        $this->assertSame('SKU-26', $products->first()->sku);
        parse_str(parse_url($products->url(2), PHP_URL_QUERY), $query);
        foreach ($params as $key => $value) {
            $this->assertEquals($value, $query[$key]);
        }
        // The control belongs to the GET filter form, whose submissions omit the old page.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@id="product-list-footer"]//select[@name="per_page" and @form="product-filters"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="product-filters"]//*[@name="page"]')->length);
        $this->assertSame((string) $size, $xpath->query('//select[@name="per_page"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    public function test_page_size_control_remains_available_for_empty_and_single_page_results(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee('0–0 / 0 találat')->assertSee('id="product-per-page"', false);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product($category, 'ONE');
        $this->get(route('products.index'))->assertOk()->assertSee('1–1 / 1 találat')->assertSee('id="product-per-page"', false);
    }

    public function test_second_page_contains_only_remaining_products(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        for ($i = 1; $i <= 26; $i++) {
            $this->product($category, sprintf('SKU-%02d', $i));
        }
        $response = $this->get(route('products.index', ['per_page' => 25, 'page' => 2, 'sort_by' => 'sku', 'sort_dir' => 'asc']))->assertOk();
        $this->assertSame(['SKU-26'], $response->viewData('products')->pluck('sku')->all());
        $response->assertSee('26–26 / 26 találat');
    }

    public static function sortOrders(): array
    {
        $cases = [];
        foreach (['product_name', 'sku', 'price', 'category_name', 'total_stock', 'stock_value', 'created_at'] as $field) {
            foreach (['asc', 'desc'] as $direction) {
                $cases[$field.' '.$direction] = [$field, $direction];
            }
        }
        return $cases;
    }

    #[DataProvider('sortOrders')]
    public function test_each_sort_field_works_in_both_directions(string $field, string $direction): void
    {
        $a = Category::create(['category_name' => 'Alpha']);
        $b = Category::create(['category_name' => 'Zeta']);
        $warehouse = Warehouse::create(['warehouse_name' => 'A', 'location' => 'A']);
        $high = $this->product($b, 'Z-02', ['product_name' => 'Zeta', 'price' => 200, 'created_at' => '2026-02-01 12:00:00']);
        $low = $this->product($a, 'A-01', ['product_name' => 'Alpha', 'price' => 100, 'created_at' => '2026-01-01 12:00:00']);
        $high->forceFill(['created_at' => '2026-02-01 12:00:00'])->save();
        $low->forceFill(['created_at' => '2026-01-01 12:00:00'])->save();
        $this->stock($high, $warehouse, 20);
        $this->stock($low, $warehouse, 2);
        $response = $this->get(route('products.index', ['sort_by' => $field, 'sort_dir' => $direction]))->assertOk();
        $expected = $direction === 'asc' ? [$low->product_id, $high->product_id] : [$high->product_id, $low->product_id];
        $this->assertSame($expected, $response->viewData('products')->pluck('product_id')->all());
        $response->assertDontSee('Aktív szűrők:');
    }

    public static function thresholdCases(): array
    {
        return [
            'below category minimum' => [null, 9, 'low', true],
            'at category minimum' => [null, 10, 'low', false],
            'at minimum is adequate' => [null, 10, 'ok', true],
            'override lowers minimum' => [3, 5, 'ok', true],
            'override raises minimum' => [20, 15, 'low', true],
            'zero override disables minimum alert' => [0, 0, 'low', false],
            'zero is still out of stock' => [0, 0, 'out', true],
            'zero is never adequate' => [0, 0, 'ok', false],
        ];
    }

    #[DataProvider('thresholdCases')]
    public function test_stock_filters_respect_threshold_boundaries_and_product_override(?int $override, int $quantity, string $status, bool $included): void
    {
        $category = Category::create(['category_name' => 'Teszt', 'default_item_min_stock' => 10]);
        $warehouse = Warehouse::create(['warehouse_name' => 'A', 'location' => 'A']);
        $product = $this->product($category, 'TEST', ['min_stock_override' => $override]);
        $this->stock($product, $warehouse, $quantity);
        $response = $this->get(route('products.index', ['stock_status' => $status]))->assertOk();
        $this->assertSame($included ? [$product->product_id] : [], $response->viewData('products')->pluck('product_id')->all());
    }

    public function test_category_filter_excludes_siblings_and_ancestors(): void
    {
        $root = Category::create(['category_name' => 'Root']);
        $child = Category::create(['category_name' => 'Child', 'parent_id' => $root->category_id]);
        $sibling = Category::create(['category_name' => 'Sibling', 'parent_id' => $root->category_id]);
        $leaf = Category::create(['category_name' => 'Leaf', 'parent_id' => $child->category_id]);
        $this->product($root, 'ROOT');
        $this->product($sibling, 'SIBLING');
        $this->product($child, 'CHILD');
        $this->product($leaf, 'LEAF');
        $response = $this->get(route('products.index', ['category_id' => $child->category_id, 'sort_by' => 'sku', 'sort_dir' => 'asc']))->assertOk();
        $this->assertSame(['CHILD', 'LEAF'], $response->viewData('products')->pluck('sku')->all());
    }

    public function test_warehouse_filter_excludes_zero_missing_and_other_warehouse_stock(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        $a = Warehouse::create(['warehouse_name' => 'A', 'location' => 'A']);
        $b = Warehouse::create(['warehouse_name' => 'B', 'location' => 'B']);
        $this->stock($this->product($category, 'ZERO'), $a, 0);
        $this->stock($this->product($category, 'OTHER'), $b, 5);
        $this->product($category, 'MISSING');
        $this->stock($this->product($category, 'MATCH'), $a, 1);
        $response = $this->get(route('products.index', ['warehouse_id' => $a->warehouse_id]))->assertOk();
        $this->assertSame(['MATCH'], $response->viewData('products')->pluck('sku')->all());
    }

    public function test_pdf_exports_all_filtered_results_not_only_current_page(): void
    {
        $category = Category::create(['category_name' => 'Teszt']);
        for ($i = 1; $i <= 12; $i++) {
            $this->product($category, sprintf('SKU-%02d', $i));
        }
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            return $view === 'pdf.inventory' && $data['products']->count() === 12
                && $data['products']->first()->sku === 'SKU-01' && $data['products']->last()->sku === 'SKU-12';
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('export.inventory', ['search' => 'Teszt', 'per_page' => 10, 'page' => 2, 'sort_by' => 'sku', 'sort_dir' => 'asc']))->assertOk();
    }

    public function test_guests_cannot_access_product_listing(): void
    {
        auth()->logout();
        $this->get(route('products.index'))->assertRedirect(route('login'));
    }
}
