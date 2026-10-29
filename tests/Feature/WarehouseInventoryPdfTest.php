<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse};
use App\Support\WarehouseInventoryPdfOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseInventoryPdfTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Warehouse $other;
    private Product $product;
    private Product $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Helyi raktár', 'location' => 'Helyi cím']);
        $this->other = Warehouse::create(['warehouse_name' => 'Idegen raktár', 'location' => 'Másik cím']);
        $category = Category::create(['category_name' => 'PDF kategória']);
        $this->product = Product::create(['product_name' => 'Helyi termék', 'sku' => 'LOCAL-SKU', 'price' => 99, 'category_id' => $category->category_id]);
        $this->foreign = Product::create(['product_name' => 'Idegen termék', 'sku' => 'FOREIGN-SKU', 'price' => 88, 'category_id' => $category->category_id]);
        Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 13579]);
        Stock::create(['warehouse_id' => $this->other->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 987654]);
        Stock::create(['warehouse_id' => $this->other->warehouse_id, 'product_id' => $this->foreign->product_id, 'quantity' => 8]);
    }

    private function url(array $params = []): string
    {
        return route('warehouses.inventory-pdf', ['warehouse' => $this->warehouse] + $params);
    }

    private function rendered(array $options = [], bool $partial = true): string
    {
        return view('pdf.warehouse-inventory', [
            'warehouse' => $this->warehouse,
            'stocks' => $this->warehouse->stocks()->with('product.category')->get(),
            'options' => array_replace(WarehouseInventoryPdfOptions::DEFAULTS, $options),
            'filterInfo' => ['Teszt szűrés'], 'partial' => $partial, 'exportedAt' => now(),
        ])->render();
    }

    public function test_template_uses_local_quantities_and_mandatory_scope_even_without_filter_details(): void
    {
        $html = $this->rendered(['show_filters' => false]);
        foreach (['13 579', 'Helyi raktár', 'részleges leltár', 'LOCAL-SKU'] as $text) $this->assertStringContainsString($text, $html);
        foreach (['987 654', 'Idegen termék', 'Teszt szűrés', 'PDF kategória'] as $text) $this->assertStringNotContainsString($text, $html);
        $this->assertStringContainsString('Teljes raktári készletsor-lista', $this->rendered([], false));
    }

    public function test_blind_template_omits_quantities_and_totals_and_optional_sections(): void
    {
        $html = $this->rendered(['blind_mode' => true, 'show_sku' => false, 'show_signatures' => false, 'show_category' => true, 'show_note_column' => true]);
        foreach (['VAKLELTÁR', 'Számolt mennyiség', 'Megjegyzés', 'PDF kategória', 'Helyi termék'] as $text) $this->assertStringContainsString($text, $html);
        foreach (['13 579', 'Helyi készlet (db)', 'Összes helyi mennyiség', 'LOCAL-SKU', 'Leltározó neve'] as $text) $this->assertStringNotContainsString($text, $html);
    }

    public function test_export_ignores_pagination_but_keeps_local_filter_and_sort(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $product = Product::create(['product_name' => 'Lapozott '.sprintf('%02d', $i), 'sku' => 'PAGE-'.$i, 'price' => 1, 'category_id' => $this->product->category_id]);
            Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $product->product_id, 'quantity' => $i]);
        }
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertSame('pdf.warehouse-inventory', $view);
            $this->assertCount(18, $data['stocks']);
            $this->assertSame(range(17, 0), $data['stocks']->pluck('quantity')->map(fn ($q) => (int) $q)->all());
            $this->assertTrue($data['partial']);
            $this->assertSame([$this->warehouse->warehouse_id], $data['stocks']->pluck('warehouse_id')->unique()->all());
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('ok'));
        $this->get($this->url(['search' => 'Lapozott', 'stock' => 'all', 'sort_by' => 'quantity', 'sort_dir' => 'desc', 'page' => 2, 'per_page' => 15, 'warehouse_id' => $this->other->warehouse_id]))->assertOk();
    }

    public function test_blind_mode_forces_count_column_and_hides_zero_stock_filter_description(): void
    {
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertTrue($data['options']['show_count_column']);
            $this->assertTrue($data['options']['blind_mode']);
            $this->assertStringNotContainsString('nulla készlet', implode(' ', $data['filterInfo']));
            $this->assertCount(0, $data['stocks']);
            $this->assertStringContainsString('colspan="4"', view($view, $data)->render());
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('stream')->once()->andReturn(response('ok'));
        $this->get($this->url(['blind_mode' => 1, 'show_count_column' => 0, 'stock' => 'zero', 'preview' => 1]))->assertOk();
    }

    public function test_all_local_rows_includes_zero_without_adding_unstocked_products(): void
    {
        Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->foreign->product_id, 'quantity' => 0]);
        Product::create(['product_name' => 'Készletsor nélküli', 'sku' => 'UNSTOCKED', 'price' => 1, 'category_id' => $this->product->category_id]);
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertFalse($data['partial']);
            $this->assertCount(2, $data['stocks']);
            $this->assertSame(13579, (int) $data['stocks']->sum('quantity'));
            $this->assertStringContainsString('Teljes raktári készletsor-lista', view($view, $data)->render());
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('ok'));
        $this->get($this->url(['stock' => 'all']))->assertOk();
    }

    public function test_exact_local_product_is_preserved_in_export_and_filter_description(): void
    {
        Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->foreign->product_id, 'quantity' => 2]);
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertSame([$this->product->product_id], $data['stocks']->pluck('product_id')->all());
            $this->assertTrue($data['partial']);
            $this->assertStringContainsString('Helyi termék (LOCAL-SKU)', implode(' ', $data['filterInfo']));
            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('ok'));
        $this->get($this->url(['stock' => 'all', 'product_id' => $this->product->product_id]))->assertOk();
    }

    public static function orientations(): array { return [['portrait', false], ['landscape', true]]; }

    #[DataProvider('orientations')]
    public function test_real_pdf_orientation_disposition_and_read_only_behavior(string $orientation, bool $preview): void
    {
        $before = Stock::orderBy('id')->get()->toArray();
        $response = $this->get($this->url(['orientation' => $orientation, 'preview' => (int) $preview]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString($preview ? 'inline' : 'attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*0(?:\.\d+)?\s+0(?:\.\d+)?\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $response->getContent(), $box));
        $this->assertSame($orientation === 'landscape', (float) $box[1] > (float) $box[2]);
        $this->assertSame($before, Stock::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public static function invalidOptions(): array
    {
        $cases = [['orientation', 'bad'], ['orientation', ['bad']], ['preview', 2], ['stock', 'bad'], ['sort_by', 'price'], ['search', ['bad']]];
        foreach (WarehouseInventoryPdfOptions::DEFAULTS as $key => $value) if (is_bool($value)) $cases[] = [$key, ['bad']];
        return $cases;
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected(string $key, mixed $value): void
    {
        $this->getJson($this->url([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public function test_foreign_exact_product_and_missing_warehouse_are_rejected(): void
    {
        $this->getJson($this->url(['product_id' => $this->foreign->product_id]))->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->getJson(route('warehouses.inventory-pdf', ['warehouse' => 999999]))->assertNotFound();
    }

    public function test_hidden_sku_is_also_omitted_from_exact_product_filter_description(): void
    {
        $listing = new \App\Queries\WarehouseStockListing(\Illuminate\Http\Request::create('/', 'GET', ['product_id' => $this->product->product_id]), $this->warehouse);
        $info = implode(' ', $listing->filterInfo(true, false));
        $this->assertStringContainsString('Helyi termék', $info);
        $this->assertStringNotContainsString('LOCAL-SKU', $info);
    }

    public function test_permissions_match_warehouse_details(): void
    {
        foreach (['worker', 'invalid'] as $role) {
            $user = User::factory()->create(['role' => 'worker']);
            $user->role = $role;
            $this->actingAs($user);
            $this->get($this->url())->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->get($this->url())->assertOk();
        auth()->forgetGuards();
        $this->get($this->url())->assertRedirect(route('login'));
    }
}
