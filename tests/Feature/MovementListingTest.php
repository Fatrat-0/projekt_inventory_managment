<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, Stock, User, Warehouse};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovementListingTest extends TestCase
{
    use RefreshDatabase;
    private Product $product;
    private Warehouse $warehouse;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker', 'name' => 'Alpha']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['category_id' => $category->category_id, 'product_name' => 'Alpha csavar', 'sku' => 'ABC-01', 'price' => 100]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Alpha', 'location' => 'A']);
    }

    private function movement(array $data = [], string $date = '2026-04-10 12:00:00'): InventoryMovement
    {
        $movement = InventoryMovement::create(array_merge([
            'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'in', 'quantity' => 5, 'reference' => 'BEV-100',
        ], $data));
        $movement->forceFill(['created_at' => $date])->save();
        return $movement;
    }

    public function test_combined_filters_and_pdf_return_the_same_ordered_records(): void
    {
        $wanted = $this->movement(['reference' => 'DOC_100%']);
        $this->movement(['type' => 'out', 'reference' => 'DOC_100%']);
        $this->movement(['reference' => 'DOCX1000']);
        $this->movement(['reference' => 'DOC_100%'], '2026-04-11 00:00:00');
        $params = ['search' => 'Alpha ABC DOC_100%', 'type' => 'in', 'product_id' => $this->product->product_id,
            'warehouse_id' => $this->warehouse->warehouse_id, 'user_id' => $this->user->id,
            'date_from' => '2026-04-10', 'date_to' => '2026-04-10', 'sort_by' => 'quantity', 'sort_dir' => 'asc'];
        $response = $this->get(route('movements.index', $params))->assertOk();
        $this->assertSame([$wanted->id], $response->viewData('movements')->pluck('id')->all());
        Pdf::shouldReceive('loadView')->once()->withArgs(fn ($view, $data) => $view === 'pdf.movements'
            && $data['movements']->pluck('id')->all() === [$wanted->id] && count($data['filterInfo']) === 7)->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('export.movements', $params))->assertOk();
    }

    public function test_date_range_includes_entire_last_day_but_not_the_next_day(): void
    {
        $this->movement([], '2026-04-09 23:59:59');
        $first = $this->movement([], '2026-04-10 00:00:00');
        $last = $this->movement([], '2026-04-10 23:59:59');
        $this->movement([], '2026-04-11 00:00:00');
        $response = $this->get(route('movements.index', ['date_from' => '2026-04-10', 'date_to' => '2026-04-10', 'sort_dir' => 'asc']))->assertOk();
        $this->assertSame([$first->id, $last->id], $response->viewData('movements')->pluck('id')->all());
    }

    public function test_summary_covers_all_filtered_pages_and_pdf_ignores_pagination(): void
    {
        for ($i = 0; $i < 12; $i++) $this->movement();
        $this->movement(['type' => 'out', 'quantity' => 9]);
        $response = $this->get(route('movements.index', ['type' => 'in', 'page' => 2, 'per_page' => 10]))->assertOk();
        $this->assertCount(2, $response->viewData('movements'));
        $this->assertSame(60, $response->viewData('totalIn'));
        $this->assertSame(0, $response->viewData('totalOut'));
        $this->assertStringContainsString('type=in', $response->viewData('movements')->url(1));
        Pdf::shouldReceive('loadView')->once()->withArgs(fn ($view, $data) => $data['movements']->count() === 12)->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('download')->once()->andReturn(response('pdf'));
        $this->get(route('export.movements', ['type' => 'in', 'page' => 2, 'per_page' => 10]))->assertOk();
    }

    public static function sorting(): array
    {
        $cases = [];
        foreach (['created_at', 'quantity', 'type', 'product_name', 'warehouse_name', 'user_name'] as $field) {
            foreach (['asc', 'desc'] as $dir) $cases[$field.' '.$dir] = [$field, $dir];
        }
        return $cases;
    }

    #[DataProvider('sorting')]
    public function test_each_sort_field_in_both_directions(string $field, string $dir): void
    {
        $otherProduct = Product::create(['category_id' => $this->product->category_id, 'product_name' => 'Zeta', 'sku' => 'Z', 'price' => 100]);
        $otherWarehouse = Warehouse::create(['warehouse_name' => 'Zeta', 'location' => 'Z']);
        $otherUser = User::factory()->create(['name' => 'Zeta']);
        $high = $this->movement(['quantity' => 20, 'type' => 'out', 'product_id' => $otherProduct->product_id, 'warehouse_id' => $otherWarehouse->warehouse_id, 'user_id' => $otherUser->id], '2026-05-01 12:00:00');
        $low = $this->movement();
        $response = $this->get(route('movements.index', ['sort_by' => $field, 'sort_dir' => $dir]))->assertOk();
        $this->assertSame($dir === 'asc' ? [$low->id, $high->id] : [$high->id, $low->id], $response->viewData('movements')->pluck('id')->all());
        $this->assertSame([], $response->viewData('filterLabels'));
    }

    public static function invalidFilters(): array
    {
        return [['per_page', 0], ['sort_by', 'invalid'], ['sort_dir', 'bad'], ['type', 'bad'], ['search', ['bad']],
            ['warehouse_id', 99999], ['product_id', 99999], ['user_id', 99999], ['date_from', '2026-02-30'], ['page', -1]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected_by_list_and_export(string $key, mixed $value): void
    {
        foreach (['movements.index', 'export.movements'] as $route) {
            $this->getJson(route($route, [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    public function test_reverse_date_range_and_invalid_pdf_orientation_are_rejected(): void
    {
        foreach (['movements.index', 'export.movements'] as $route) {
            $this->getJson(route($route, ['date_from' => '2026-04-11', 'date_to' => '2026-04-10']))->assertUnprocessable()->assertJsonValidationErrors('date_to');
        }
        $this->getJson(route('export.movements', ['orientation' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('orientation');
    }

    public function test_stock_lookup_is_read_only_and_missing_stock_means_zero(): void
    {
        $params = ['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id];
        $this->getJson(route('movements.stock', $params))->assertOk()->assertExactJson(['quantity' => 0]);
        $this->assertDatabaseCount('stocks', 0);
        Stock::create($params + ['quantity' => 17]);
        $response = $this->getJson(route('movements.stock', $params))->assertOk()->assertExactJson(['quantity' => 17]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->getJson(route('movements.stock', ['product_id' => 99999]))->assertUnprocessable();
    }

    public function test_guest_and_invalid_role_cannot_read_stock_or_list(): void
    {
        auth()->logout();
        foreach (['movements.index', 'movements.stock'] as $route) $this->getJson(route($route))->assertUnauthorized();
        $this->user->role = 'invalid';
        $this->actingAs($this->user);
        foreach (['movements.index', 'movements.stock'] as $route) $this->getJson(route($route))->assertForbidden();
    }

    public function test_empty_list_has_footer_and_form_requires_master_data(): void
    {
        $this->get(route('movements.index'))->assertOk()->assertSee('0–0 / 0 találat')->assertSee('Nincs a feltételeknek megfelelő');
        $this->product->delete();
        $this->get(route('movements.create'))->assertOk()->assertSee('legalább egy termék és egy raktár szükséges')->assertDontSee('action="'.route('movements.store').'"', false);
    }

    public function test_reference_is_saved_and_escaped_in_list(): void
    {
        $reference = '<script>alert(1)</script>';
        $this->post(route('movements.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'type' => 'in', 'quantity' => 1, 'reference' => $reference])->assertRedirect();
        $this->assertDatabaseHas('inventory_movements', ['reference' => $reference]);
        $this->get(route('movements.index'))->assertOk()->assertSee($reference)->assertDontSee($reference, false);
    }

    public function test_cancel_and_save_preserve_the_list_context(): void
    {
        $params = ['search' => 'Alpha', 'type' => 'in', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 25, 'page' => 2];
        $list = $this->get(route('movements.index', $params))->assertOk();
        $createUrl = $list->viewData('createUrl');
        parse_str(parse_url($createUrl, PHP_URL_QUERY), $query);
        $form = $this->get($createUrl)->assertOk();
        parse_str(parse_url($form->viewData('returnUrl'), PHP_URL_QUERY), $returnQuery);
        foreach ($params as $key => $value) $this->assertEquals($value, $returnQuery[$key]);
        $this->post(route('movements.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'type' => 'in', 'quantity' => 1, 'context' => $query['context'],
        ])->assertRedirect($form->viewData('returnUrl'));
    }

    public function test_tampered_context_cannot_redirect_to_another_site(): void
    {
        foreach (['https://example.test', 'not-an-encrypted-context', ['bad']] as $context) {
            $form = $this->get(route('movements.create', ['context' => $context]))->assertOk();
            $this->assertSame(route('movements.index'), $form->viewData('returnUrl'));
            $this->post(route('movements.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(),
                'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
                'type' => 'in', 'quantity' => 1, 'context' => $context,
            ])->assertRedirect(route('movements.index'));
        }
    }

    public function test_field_errors_are_linked_to_controls_and_preserve_context(): void
    {
        $context = \App\Support\MovementListContext::encode(['search' => 'Alpha', 'sort_by' => 'quantity']);
        $createUrl = route('movements.create', ['context' => $context]);
        $this->from($createUrl)->post(route('movements.store'), ['request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => '', 'warehouse_id' => '', 'type' => 'bad', 'quantity' => -1,
            'reference' => str_repeat('a', 256), 'context' => $context,
        ])->assertRedirect($createUrl)->assertSessionHasErrors(['product_id','warehouse_id','type','quantity','reference']);
        $response = $this->get($createUrl)->assertOk();
        foreach (['movement-product', 'movement-warehouse', 'movement-type', 'movement-quantity', 'movement-reference'] as $id) {
            $response->assertSee('id="'.$id.'-error"', false)->assertSee('aria-describedby="'.$id.'-error"', false);
        }
        $this->assertStringContainsString('search=Alpha', $response->viewData('returnUrl'));
    }
}
