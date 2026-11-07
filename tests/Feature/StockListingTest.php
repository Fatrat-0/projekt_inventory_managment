<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse};
use App\Queries\StockListing;
use App\Support\StockListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockListingTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;
    private Category $root;
    private Category $child;
    private Warehouse $budapest;
    private Warehouse $gyor;
    private array $rows;
    private Product $missing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'worker']); $this->actingAs($this->actor);
        $this->root = Category::create(['category_name' => 'Szerszám', 'default_item_min_stock' => 1000]);
        $this->child = Category::create(['category_name' => 'Csavar', 'parent_id' => $this->root->category_id]);
        $other = Category::create(['category_name' => 'Más']);
        $this->budapest = Warehouse::create(['warehouse_name' => 'Budapest központ', 'location' => 'Raktár utca']);
        $this->gyor = Warehouse::create(['warehouse_name' => 'Győr telephely', 'location' => 'Ipari park']);
        $alpha = $this->product('Alpha', 'SKU-100%_!', $this->root);
        $this->rows = [
            $this->stock($alpha, $this->budapest, 10, 4, 8),
            $this->stock($alpha, $this->gyor, 5, 0, 5),
            $this->stock($this->product('Beta', 'B-002', $this->child), $this->budapest, 0, 0, 0),
            $this->stock($this->product('Gamma', 'G-003', $this->child), $this->gyor, 10, 10, 5),
            $this->stock($this->product('Delta', 'D-004', $other), $this->budapest, 3, 0, 0),
        ];
        $this->missing = $this->product('Nincs készletsor', 'MISSING', $this->root);
    }
    private function product(string $name, string $sku, ?Category $category = null): Product
    {
        return Product::create(['product_name' => $name, 'sku' => $sku, 'price' => 1,
            'category_id' => ($category ?? $this->root)->category_id, 'min_stock_override' => 0]);
    }
    private function stock(Product $product, Warehouse $warehouse, int $quantity, int $quarantined = 0, int $minimum = 0): Stock
    {
        return Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id,
            'quantity' => $quantity, 'quarantined_quantity' => $quarantined, 'min_quantity' => $minimum]);
    }
    private function listing(array $filters = []): StockListing { return new StockListing(Request::create('/stock', 'GET', $filters)); }
    private function ids(array $filters = []): array { return $this->listing($filters)->query()->pluck('stocks.id')->all(); }

    public function test_default_lists_real_stock_rows_including_zero_and_eager_loads_only_paged_relations(): void
    {
        $response = $this->get('/stock')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $stocks = $response->viewData('stocks');
        $this->assertSame(5, $stocks->total());
        $this->assertNotContains($this->missing->product_id, $stocks->pluck('product_id')->all());
        $this->assertContains($this->rows[2]->id, $stocks->pluck('id')->all());
        $this->assertTrue($stocks->first()->relationLoaded('product'));
        $this->assertTrue($stocks->first()->product->relationLoaded('category'));
        $this->assertTrue($stocks->first()->relationLoaded('warehouse'));
    }

    public static function statuses(): array
    {
        return [['all', [0,1,2,3,4]], ['low', [0,3]], ['out', [2,3]], ['positive', [0,1,4]], ['quarantined', [0,3]], ['zero', [2]], ['ok', [1,4]]];
    }
    #[DataProvider('statuses')]
    public function test_statuses_use_local_available_stock_with_exact_and_zero_minimum_boundaries(string $status, array $indices): void
    {
        $expected = array_map(fn ($index) => $this->rows[$index]->id, $indices);
        $this->assertEqualsCanonicalizing($expected, $this->ids(['stock_status' => $status]));
    }

    public function test_search_requires_every_word_across_product_sku_category_warehouse_and_address_and_escapes_literals(): void
    {
        $this->assertSame([$this->rows[0]->id], $this->ids(['search' => 'Alpha 100%_! Budapest']));
        $this->assertSame([$this->rows[0]->id], $this->ids(['search' => 'Alpha Raktár utca']));
        $this->assertSame([$this->rows[3]->id], $this->ids(['search' => 'Gamma Csavar Ipari']));
        $this->assertEqualsCanonicalizing([$this->rows[0]->id, $this->rows[1]->id], $this->ids(['search' => '%_!']));
        $this->assertSame([], $this->ids(['search' => 'Budapest Győr']));
        $this->assertSame([], $this->ids(['search' => "' OR 1=1 --"]));
        $this->assertCount(5, $this->ids(['search' => "  \t  "]));
    }

    public function test_filters_compose_and_category_includes_descendants_without_creating_missing_stock_rows(): void
    {
        $this->assertCount(4, $this->ids(['category_id' => $this->root->category_id]));
        $this->assertEqualsCanonicalizing([$this->rows[2]->id, $this->rows[3]->id], $this->ids(['category_id' => $this->child->category_id]));
        $this->assertSame([$this->rows[0]->id], $this->ids(['product_id' => $this->rows[0]->product_id,
            'warehouse_id' => $this->budapest->warehouse_id, 'category_id' => $this->root->category_id, 'stock_status' => 'low', 'search' => 'Alpha']));
        $this->assertSame([], $this->ids(['product_id' => $this->missing->product_id]));
        $this->root->update(['parent_id' => $this->child->category_id]);
        $this->assertCount(4, $this->ids(['category_id' => $this->root->category_id]));
    }

    public static function sorts(): array
    {
        $cases = []; foreach (array_keys(StockListing::SORTS) as $key) foreach (['asc', 'desc'] as $direction) $cases[] = [$key, $direction]; return $cases;
    }
    #[DataProvider('sorts')]
    public function test_every_sort_is_ordered_and_uses_the_same_direction_for_id_ties(string $key, string $direction): void
    {
        $value = fn ($stock) => match ($key) {
            'product_name', 'sku' => $stock->product->$key,
            'warehouse_name' => $stock->warehouse->warehouse_name,
            'available' => $stock->available_quantity,
            default => $stock->$key,
        };
        $expected = collect($this->rows)->sort(function ($a, $b) use ($value, $direction) {
            $compared = ($value($a) <=> $value($b)) ?: ($a->id <=> $b->id);
            return $direction === 'asc' ? $compared : -$compared;
        })->pluck('id')->all();
        $this->assertSame($expected, $this->ids(['sort_by' => $key, 'sort_dir' => $direction]));
        $this->get(route('stock.index', ['sort_by' => $key, 'sort_dir' => $direction]))->assertOk();
    }

    public function test_summaries_cover_all_filtered_rows_and_distinguish_products_from_warehouse_rows(): void
    {
        $summary = $this->listing()->summary();
        foreach (['rows_count' => 5, 'products_count' => 4, 'warehouses_count' => 2, 'physical' => 28, 'available' => 14, 'quarantined' => 14, 'low_count' => 2] as $field => $value) $this->assertSame($value, (int)$summary->$field);
        $summary = $this->listing(['warehouse_id' => $this->gyor->warehouse_id])->summary();
        $this->assertSame(15, (int)$summary->physical); $this->assertSame(5, (int)$summary->available);
        $summary = $this->listing(['stock_status' => 'low'])->summary();
        $this->assertSame(20, (int)$summary->physical); $this->assertSame(6, (int)$summary->available);
        $summary = $this->listing(['search' => 'Nincsilyen'])->summary();
        $this->assertSame(0, (int)$summary->rows_count); $this->assertSame(0, (int)$summary->physical);
    }

    public function test_suggestions_are_unique_products_scoped_to_filters_and_independent_of_current_search_and_exact_product(): void
    {
        $this->assertCount(4, $this->listing()->suggestions());
        $this->assertCount(4, $this->listing(['search' => 'Nincsilyen', 'product_id' => $this->missing->product_id])->suggestions());
        $suggestions = $this->listing(['warehouse_id' => $this->gyor->warehouse_id, 'stock_status' => 'out'])->suggestions();
        $this->assertSame([(string)$this->rows[3]->product_id], $suggestions->pluck('value')->all());
        $this->assertStringContainsString('G-003', $suggestions->first()['detail']);
    }

    public function test_pagination_keeps_filters_and_summaries_and_recovers_an_out_of_range_page_without_losing_feedback(): void
    {
        for ($i = 0; $i < 12; $i++) $this->stock($this->product('Extra '.$i, 'EX-'.$i), $this->budapest, 2);
        $response = $this->get('/stock?per_page=10&sort_by=quantity&sort_dir=desc')->assertOk();
        $this->assertCount(10, $response->viewData('stocks'));
        $this->assertSame(17, $response->viewData('stocks')->total());
        $this->assertSame(52, (int)$response->viewData('summary')->physical);
        $url = $response->viewData('stocks')->nextPageUrl();
        $this->assertStringContainsString('per_page=10', $url); $this->assertStringContainsString('sort_by=quantity', $url);
        $second = $this->get($url)->assertOk(); $this->assertCount(7, $second->viewData('stocks'));
        $this->assertSame([], array_intersect($response->viewData('stocks')->pluck('id')->all(), $second->viewData('stocks')->pluck('id')->all()));
        $this->withSession(['success' => 'Mentés ellenőrzése']);
        $redirect = $this->get('/stock?per_page=10&page=999')->assertRedirect();
        $this->assertStringContainsString('page=2', $redirect->headers->get('Location'));
        $this->get($redirect->headers->get('Location'))->assertOk()->assertSee('Mentés ellenőrzése');
    }

    public static function invalidFilters(): array
    {
        return [['search', ['bad']], ['search', str_repeat('a', 201)], ['product_id', 99999], ['category_id', 99999],
            ['warehouse_id', 99999], ['warehouse_id', ['1']], ['stock_status', 'bad'], ['sort_by', 'stocks.id desc'],
            ['sort_dir', 'sideways'], ['per_page', 99999], ['per_page', ['25']], ['page', 0], ['page', 2147483648],
            ['edit_minimum', ['1']], ['edit_minimum', 999999]];
    }
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_json_errors_and_html_recovers_once(string $key, mixed $value): void
    {
        $this->getJson(route('stock.index', [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->get(route('stock.index', [$key => $value]))->assertRedirect(route('stock.index'))->assertSessionHasErrors('filters');
        $this->get('/stock')->assertOk()->assertSee('A készletszűrők már nem érvényesek.');
    }

    public function test_minimum_save_preserves_the_validated_list_context_and_recovers_a_now_empty_last_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($i = 0; $i < 9; $i++) $last = $this->stock($this->product('Z '.$i, 'ZERO-'.$i), $this->budapest, 0, 0, 5);
        $filters = ['stock_status' => 'low', 'per_page' => 10, 'sort_by' => 'product_name', 'sort_dir' => 'asc', 'page' => 2];
        $this->assertSame($last->id, $this->get(route('stock.index', $filters))->viewData('stocks')->first()->id);
        $response = $this->patch(route('stock.minimum', $last), ['expected_minimum' => 5, 'min_quantity' => 0, 'context' => StockListContext::encode($filters)])->assertRedirect();
        $this->followingRedirects()->get($response->headers->get('Location'))->assertOk()->assertSee('A helyi minimum frissítve.');
        $this->assertSame(0, $last->fresh()->min_quantity);
        $this->assertSame(0, $last->fresh()->quantity);
    }

    public function test_list_context_cannot_redirect_externally_or_restore_invalid_fields(): void
    {
        foreach (['https://evil.test', ['bad'], Crypt::encryptString('{bad'), Crypt::encryptString(json_encode(['warehouse_id' => ['1']]))] as $invalid) {
            $this->assertSame(route('stock.index', ['warehouse_id' => $this->budapest->warehouse_id]), StockListContext::url($invalid, $this->budapest->warehouse_id));
        }
        $url = StockListContext::url(StockListContext::encode(['search' => 'Alpha', 'page' => 2, 'redirect' => 'https://evil.test']));
        $this->assertStringContainsString('search=Alpha', $url); $this->assertStringNotContainsString('evil', $url);
    }

    public function test_malformed_old_minimum_fields_do_not_break_the_error_page_or_change_other_rows(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->from('/stock')->patch(route('stock.minimum', $this->rows[0]), ['expected_minimum' => 8, 'min_quantity' => ['bad'], '_stock_minimum_id' => ['bad']])->assertRedirect('/stock');
        $this->get('/stock')->assertOk()->assertSee('min_quantity');
        $this->assertSame(8, $this->rows[0]->fresh()->min_quantity);
    }

    public function test_read_only_listing_handles_large_totals_and_guests_cannot_access_it(): void
    {
        foreach ([1, 2] as $i) $this->stock($this->product('Big '.$i, 'BIG-'.$i), $this->budapest, 2147483647);
        $before = Stock::orderBy('id')->get()->toArray();
        $this->assertSame(4294967322, (int)$this->get('/stock')->viewData('summary')->physical);
        $this->assertSame($before, Stock::orderBy('id')->get()->toArray());
        auth()->logout(); $this->get('/stock')->assertRedirect(route('login'));
    }

    public function test_stock_actions_are_a_real_menu_with_correct_product_and_warehouse_and_no_direct_deletion(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $html = $this->get('/stock')->assertOk()->getContent();
        $document = new \DOMDocument; @$document->loadHTML('<?xml encoding="UTF-8">'.$html); $xpath = new \DOMXPath($document);
        $id = 'stock-actions-'.$this->rows[0]->id.'-table';
        $this->assertCount(1, $xpath->query("//button[@popovertarget='{$id}']"));
        $this->assertCount(5, $xpath->query("//*[@id='{$id}']//a[@role='menuitem']"));
        $locationsUrl = route('warehouses.locations.index', $this->rows[0]->warehouse_id);
        $this->assertCount(1, $xpath->query("//*[@id='{$id}']//a[@href='{$locationsUrl}']"));
        $this->assertCount(0, $xpath->query("//*[@id='{$id}']//form[@method='POST']"));
        $this->assertCount(0, $xpath->query("//*[@id='{$id}']//*[contains(text(),'Törlés')]"));
        $html = $this->get('/stock?product_id='.$this->rows[0]->product_id.'&warehouse_id='.$this->budapest->warehouse_id)->assertOk()->getContent();
        $this->assertStringContainsString(htmlspecialchars(route('movements.create', ['product_id' => $this->rows[0]->product_id, 'warehouse_id' => $this->budapest->warehouse_id])), $html);
    }

    public function test_workers_cannot_open_a_minimum_editor_by_using_the_native_fallback_url(): void
    {
        $html = $this->get('/stock?edit_minimum='.$this->rows[0]->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('Helyi minimum módosítása', $html);
        $this->assertStringNotContainsString('name="expected_minimum"', $html);
        $this->assertStringContainsString('Termék szerkesztése', $html);
    }

    public function test_minimum_editor_has_a_server_rendered_fallback_without_modifying_stock(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $before = Stock::get()->toArray();
        $html = $this->get('/stock?edit_minimum='.$this->rows[0]->id)->assertOk()->getContent();
        $document = new \DOMDocument; @$document->loadHTML('<?xml encoding="UTF-8">'.$html); $xpath = new \DOMXPath($document);
        $editors = $xpath->query("//*[@data-stock-minimum='{$this->rows[0]->id}']/div[@x-show]");
        $this->assertCount(2, $editors);
        foreach ($editors as $editor) $this->assertFalse($editor->hasAttribute('style'));
        $this->assertSame($before, Stock::get()->toArray());
    }

    public function test_new_movement_prefills_are_validated_and_do_not_change_stock(): void
    {
        $before = Stock::get()->toArray();
        $params = ['product_id' => $this->rows[0]->product_id, 'warehouse_id' => $this->budapest->warehouse_id];
        $this->get(route('movements.create', $params))->assertOk()->assertViewHas('prefill', $params);
        $this->getJson(route('movements.create', ['product_id' => 999999]))->assertUnprocessable();
        $this->getJson(route('movements.create', ['warehouse_id' => ['1']]))->assertUnprocessable();
        $this->assertSame($before, Stock::get()->toArray());
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
