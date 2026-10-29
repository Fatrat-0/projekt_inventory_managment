<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseStockLinksTest extends TestCase
{
    use RefreshDatabase;

    private function assertCountMatchesProducts(Warehouse $warehouse, int $expected): void
    {
        $rows = $this->get(route('warehouses.index', ['warehouse_id' => $warehouse->warehouse_id]))->assertOk()->viewData('warehouses');
        $this->assertSame($expected, $rows->first()->products_count);
        $products = $this->get(route('products.index', ['warehouse_id' => $warehouse->warehouse_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']))->assertOk()->viewData('products');
        $this->assertSame($expected, $products->total());
    }

    public function test_counter_and_linked_product_list_agree_with_zero_other_warehouse_and_in_transit_stock(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        $category = Category::create(['category_name' => 'Teszt']);
        $target = Warehouse::create(['warehouse_name' => 'Cél raktár', 'location' => 'Cél']);
        $other = Warehouse::create(['warehouse_name' => 'Másik raktár', 'location' => 'Másik']);
        $makeProduct = fn($sku) => Product::create(['product_name' => $sku, 'sku' => $sku, 'price' => 100, 'category_id' => $category->category_id]);
        $positive = $makeProduct('POSITIVE');
        $zero = $makeProduct('ZERO-AT-TARGET');
        $travelling = $makeProduct('TRAVELLING');
        Stock::create(['warehouse_id' => $target->warehouse_id, 'product_id' => $positive->product_id, 'quantity' => 3]);
        Stock::create(['warehouse_id' => $other->warehouse_id, 'product_id' => $positive->product_id, 'quantity' => 9]);
        Stock::create(['warehouse_id' => $target->warehouse_id, 'product_id' => $zero->product_id, 'quantity' => 0]);
        Stock::create(['warehouse_id' => $other->warehouse_id, 'product_id' => $zero->product_id, 'quantity' => 7]);
        Stock::create(['warehouse_id' => $other->warehouse_id, 'product_id' => $travelling->product_id, 'quantity' => 6]);
        app(InventoryService::class)->startTransfer(['from_warehouse_id' => $other->warehouse_id, 'to_warehouse_id' => $target->warehouse_id, 'product_id' => $travelling->product_id, 'quantity' => 2, 'user_id' => $actor->id]);
        $listing = $this->get(route('warehouses.index', ['warehouse_id' => $target->warehouse_id]))->assertOk();
        $count = $listing->viewData('warehouses')->first()->products_count;
        $this->assertSame(1, $count);
        $url = route('products.index', ['warehouse_id' => $target->warehouse_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']);
        $listing->assertSee(e($url), false);
        $products = $this->get($url)->assertOk()->viewData('products');
        $this->assertSame($count, $products->total());
        $this->assertSame([$positive->product_id], $products->pluck('product_id')->all());
        $this->assertEquals(12, $products->first()->total_stock);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $target->warehouse_id, 'product_id' => $positive->product_id, 'quantity' => 3]);
    }

    public function test_zero_counter_links_to_an_empty_list_even_when_products_exist_elsewhere(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $category = Category::create(['category_name' => 'Teszt']);
        $empty = Warehouse::create(['warehouse_name' => 'Üres raktár', 'location' => 'Üres']);
        $other = Warehouse::create(['warehouse_name' => 'Másik raktár', 'location' => 'Másik']);
        $product = Product::create(['product_name' => 'Termék', 'sku' => 'ELSEWHERE', 'price' => 100, 'category_id' => $category->category_id]);
        Stock::create(['warehouse_id' => $empty->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 0]);
        Stock::create(['warehouse_id' => $other->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 8]);
        $this->get(route('warehouses.index', ['warehouse_id' => $empty->warehouse_id]))->assertOk()
            ->assertSee('Üres raktár: 0 készleten lévő termékfajta megtekintése');
        $this->get(route('products.index', ['warehouse_id' => $empty->warehouse_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']))
            ->assertOk()->assertViewHas('products', fn($products) => $products->total() === 0);
    }

    public function test_count_represents_all_products_and_pagination_keeps_the_warehouse_filter(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $warehouse = Warehouse::create(['warehouse_name' => 'Lapozott raktár', 'location' => 'Cím']);
        $category = Category::create(['category_name' => 'Teszt']);
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $name = 'Termék '.str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $product = Product::create(['product_name' => $name, 'sku' => 'P-'.$i, 'price' => 100, 'category_id' => $category->category_id]);
            Stock::create(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 1]);
            $ids[] = $product->product_id;
        }
        $this->assertCountMatchesProducts($warehouse, 13);
        $firstPage = $this->get(route('products.index', ['warehouse_id' => $warehouse->warehouse_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']))->assertOk()->viewData('products');
        $this->assertCount(10, $firstPage);
        $nextUrl = $firstPage->nextPageUrl();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY), $params);
        $this->assertSame((string)$warehouse->warehouse_id, $params['warehouse_id']);
        $secondPage = $this->get($nextUrl)->assertOk()->viewData('products');
        $this->assertCount(3, $secondPage);
        $this->assertSame($ids, array_merge($firstPage->pluck('product_id')->all(), $secondPage->pluck('product_id')->all()));
    }

    public static function transferOutcomes(): array { return [['received', true], ['cancelled', false]]; }

    #[DataProvider('transferOutcomes')]
    public function test_counts_follow_real_transfer_receipt_and_cancellation(string $outcome, bool $complete): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        $category = Category::create(['category_name' => 'Teszt']);
        $product = Product::create(['product_name' => 'Áru', 'sku' => 'TRANSFER-COUNT', 'price' => 100, 'category_id' => $category->category_id]);
        $source = Warehouse::create(['warehouse_name' => 'Forrás', 'location' => 'A']);
        $target = Warehouse::create(['warehouse_name' => 'Cél', 'location' => 'B']);
        Stock::create(['warehouse_id' => $source->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 2]);
        $service = app(InventoryService::class);
        $this->assertCountMatchesProducts($source, 1);
        $this->assertCountMatchesProducts($target, 0);
        $transfer = $service->startTransfer(['from_warehouse_id' => $source->warehouse_id, 'to_warehouse_id' => $target->warehouse_id, 'product_id' => $product->product_id, 'quantity' => 2, 'user_id' => $actor->id]);
        $this->assertCountMatchesProducts($source, 0);
        $this->assertCountMatchesProducts($target, 0);
        $service->finalizeTransfer($transfer, $complete);
        $this->assertCountMatchesProducts($source, $complete ? 0 : 1);
        $this->assertCountMatchesProducts($target, $complete ? 1 : 0);
        $this->assertSame($complete ? 'completed' : 'cancelled', $transfer->fresh()->status, $outcome);
    }
}
