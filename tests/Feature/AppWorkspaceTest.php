<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use App\Support\{AppSearch, AppTasks};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    private User $worker;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = User::factory()->create(['role' => 'worker']); $this->actingAs($this->worker);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'ÉKEZETES csavar', 'sku' => 'CS-100%_!', 'price' => 1, 'category_id' => $category->category_id]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Fő raktár']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 20]);
    }

    public function test_empty_search_exposes_only_real_permitted_navigation_destinations(): void
    {
        $worker = $this->getJson('/search')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('results');
        $this->assertCount(6, $worker);
        foreach ($worker as $result) { $this->assertSame('Oldal', $result['kind']); $this->get($result['url'])->assertOk(); }
        foreach (['admin', 'manager'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/search')->assertOk()->assertJsonCount(9, 'results');
        }
    }

    public function test_search_combines_words_and_normalizes_hungarian_case_and_accents_without_treating_wildcards_as_patterns(): void
    {
        foreach (['ekezetes CS', 'ÉKEZETES csavar', '100%_!'] as $query) {
            $response = $this->getJson(route('app-search.index', ['q' => $query]))->assertOk();
            $this->assertSame([route('products.show', $this->product)], array_column($response->json('results'), 'url'));
        }
        $this->getJson('/search?q=keszlet')->assertOk()->assertJsonFragment(['label' => 'Készlet']);
        $this->getJson('/search?q=nemletezik')->assertJsonCount(0, 'results');
        $this->getJson('/search?q='.urlencode("' OR 1=1 --"))->assertJsonCount(0, 'results');
    }

    public function test_product_results_are_bounded_and_further_results_preserve_the_query(): void
    {
        for ($i = 0; $i < 12; $i++) Product::create(['product_name' => 'Limit teszt '.$i, 'sku' => 'LIMIT-'.$i, 'price' => 1, 'category_id' => $this->product->category_id]);
        $this->getJson('/search?q=LIMIT-')->assertOk()->assertJsonCount(8, 'results')->assertJsonPath('moreUrl', route('products.index', ['search' => 'LIMIT-']));
        $this->getJson('/search?q=CS-100')->assertJsonPath('moreUrl', null);
    }

    public function test_unknown_roles_do_not_receive_product_results_or_workflow_counts(): void
    {
        $other = clone $this->worker; $other->role = 'viewer';
        $this->assertSame([], AppSearch::results($other, 'CS-100')['results']);
        $this->assertNull(AppTasks::summary($other));
    }

    public function test_return_quality_and_transfer_counts_use_remaining_open_business_records_only(): void
    {
        $source = InventoryMovement::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->worker->id, 'type' => 'out', 'quantity' => 10]);
        $service = app(InventoryService::class);
        $pending = $service->recordReturn($source, ['user_id' => $this->worker->id, 'quantity' => 4, 'return_reason' => 'Teszt']);
        $service->processReturnQuality($pending, ['action' => 'release', 'quantity' => 1, 'reason' => 'Megfelelő', 'user_id' => $this->worker->id]);
        $closed = $service->recordReturn($source, ['user_id' => $this->worker->id, 'quantity' => 2, 'return_reason' => 'Lezárt']);
        $service->processReturnQuality($closed, ['action' => 'release', 'quantity' => 2, 'reason' => 'Megfelelő', 'user_id' => $this->worker->id]);
        $destination = Warehouse::create(['warehouse_name' => 'Másik']);
        foreach (['pending', 'completed', 'cancelled'] as $status) InventoryTransfer::create(['product_id' => $this->product->product_id,
            'from_warehouse_id' => $this->warehouse->warehouse_id, 'to_warehouse_id' => $destination->warehouse_id, 'quantity' => 1, 'status' => $status, 'user_id' => $this->worker->id]);
        $this->assertSame(['transfers' => 1, 'returns' => 1, 'returnQuantity' => 3, 'total' => 2], AppTasks::summary($this->worker));
        $this->get('/stock')->assertOk()->assertSee('Nyitott feladatok, 2 tétel')->assertSee(route('quality-returns.index', ['status' => 'open']), false);
    }

    public function test_search_and_topbar_rendering_do_not_change_inventory_and_user_content_is_escaped(): void
    {
        $this->product->update(['product_name' => '<script>alert(1)</script>']);
        $stocks = Stock::get()->toArray();
        $this->get('/search?q=CS-100')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/dashboard')->assertOk()->assertSee('Globális gyorskereső')->assertSee('Nyitott feladatok, 0 tétel');
        $this->assertSame($stocks, Stock::get()->toArray());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public static function invalidQueries(): array { return [[['bad']], [str_repeat('a', 201)]]; }
    #[DataProvider('invalidQueries')]
    public function test_invalid_search_is_a_json_error_or_a_single_html_recovery(mixed $query): void
    {
        $this->getJson(route('app-search.index', ['q' => $query]))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->get(route('app-search.index', ['q' => $query]))->assertRedirect(route('app-search.index'));
        $this->get('/search')->assertOk()->assertSee('A keresés legfeljebb 200 karakteres szöveg lehet.');
    }

    public static function listPages(): array
    {
        return array_map(fn ($path) => [$path], ['/dashboard', '/products', '/stock', '/movements', '/transfers', '/shipments', '/categories', '/warehouses', '/partners', '/users', '/quality-returns']);
    }
    #[DataProvider('listPages')]
    public function test_list_workspaces_are_full_width_with_contextual_toolbars(string $path): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertStringContainsString('class="app-page', $html);
        $this->assertStringContainsString('data-page-toolbar', $html);
        $this->assertStringNotContainsString('max-w-7xl', $html);
    }

    public function test_guests_cannot_read_global_search(): void
    {
        auth()->logout(); $this->get('/search')->assertRedirect(route('login')); $this->getJson('/search?q=CS')->assertUnauthorized();
    }

    public function test_product_search_destinations_show_real_read_only_product_details_and_links(): void
    {
        Stock::query()->update(['quarantined_quantity' => 3]);
        $before = Stock::get()->toArray();
        $this->get(route('products.show', $this->product))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('ÉKEZETES csavar')->assertSee('17')->assertSee('Raktári készlet')->assertSee('Termék szerkesztése')
            ->assertSee(route('stock.index', ['product_id' => $this->product->product_id]), false)
            ->assertSee(route('movements.index', ['product_id' => $this->product->product_id]), false);
        $this->assertSame($before, Stock::get()->toArray());
        $this->get('/products/999999')->assertNotFound();
    }

    public function test_product_details_handle_missing_stock_and_an_explicit_zero_global_minimum(): void
    {
        $empty = Product::create(['product_name' => 'Üres termék', 'sku' => 'EMPTY', 'price' => 0, 'category_id' => $this->product->category_id, 'min_stock_override' => 0]);
        $this->get(route('products.show', $empty))->assertOk()->assertSee('0 raktári készletsorból')->assertSee('0 db')->assertDontSee('Nincs felülbírálva');
    }
}
