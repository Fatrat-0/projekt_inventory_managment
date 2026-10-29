<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Shipment, Stock, User, Warehouse};
use App\Support\WarehouseListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->actor);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Alpha raktár', 'location' => 'Budapest, Fő utca 1.']);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'Termék', 'sku' => 'WH-001', 'price' => 100, 'category_id' => $category->category_id]);
    }

    public function test_search_requires_every_word_across_name_and_address_and_treats_wildcards_literally(): void
    {
        Warehouse::create(['warehouse_name' => 'Zeta 100%_! raktár', 'location' => 'Debrecen']);
        $this->get(route('warehouses.index', ['search' => 'Alpha Budapest']))->assertOk()->assertViewHas('warehouses', fn($rows) => $rows->total() === 1 && $rows->first()->is($this->warehouse));
        $this->get(route('warehouses.index', ['search' => 'Alpha Debrecen']))->assertViewHas('warehouses', fn($rows) => $rows->total() === 0);
        $this->get(route('warehouses.index', ['search' => '%_!']))->assertViewHas('warehouses', fn($rows) => $rows->total() === 1 && $rows->first()->warehouse_name === 'Zeta 100%_! raktár');
    }

    public function test_stock_totals_filters_and_sorting_include_zero_and_unused_warehouses_correctly(): void
    {
        $empty = Warehouse::create(['warehouse_name' => 'Empty', 'location' => 'Cím']);
        $zero = Warehouse::create(['warehouse_name' => 'Zero', 'location' => 'Cím']);
        Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 7]);
        Stock::create(['warehouse_id' => $zero->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 0]);
        $this->get(route('warehouses.index', ['sort_by' => 'total_quantity', 'sort_dir' => 'desc']))->assertOk()
            ->assertViewHas('warehouses', function ($rows) use ($empty, $zero) {
                $this->assertSame($this->warehouse->warehouse_id, $rows->first()->warehouse_id);
                $this->assertEquals(7, $rows->first()->total_quantity);
                $this->assertSame(1, $rows->first()->products_count);
                $this->assertTrue($rows->first()->is_in_use);
                $this->assertEquals(0, $rows->firstWhere('warehouse_id', $empty->warehouse_id)->total_quantity);
                $this->assertFalse($rows->firstWhere('warehouse_id', $empty->warehouse_id)->is_in_use);
                $this->assertTrue($rows->firstWhere('warehouse_id', $zero->warehouse_id)->is_in_use);
                $this->assertSame(0, $rows->firstWhere('warehouse_id', $zero->warehouse_id)->products_count);
                return true;
            });
        $this->get(route('warehouses.index', ['stock' => 'stocked']))->assertViewHas('warehouses', fn($rows) => $rows->total() === 1);
        $this->get(route('warehouses.index', ['stock' => 'empty']))->assertViewHas('warehouses', fn($rows) => $rows->total() === 2);
    }

    public static function sorts(): array { return array_map(fn($key) => [$key], ['warehouse_name', 'location', 'products_count', 'total_quantity']); }

    #[DataProvider('sorts')]
    public function test_sorting_is_stable_and_page_size_is_applied(string $sort): void
    {
        Warehouse::query()->delete();
        for ($i = 0; $i < 17; $i++) Warehouse::create(['warehouse_name' => 'Azonos', 'location' => 'Azonos']);
        $expected = Warehouse::orderBy('warehouse_id')->pluck('warehouse_id')->slice(15)->values()->all();
        $this->get(route('warehouses.index', ['sort_by' => $sort, 'sort_dir' => 'desc', 'per_page' => 15, 'page' => 2]))->assertOk()
            ->assertViewHas('warehouses', fn($rows) => $rows->total() === 17 && $rows->pluck('warehouse_id')->all() === $expected);
    }

    public static function badFilters(): array
    {
        return [['search', ['bad']], ['search', str_repeat('x', 201)], ['warehouse_id', 999999], ['warehouse_id', ['bad']], ['stock', 'bad'], ['sort_by', 'bad'], ['sort_dir', 'bad'], ['per_page', 16], ['page', 0]];
    }

    #[DataProvider('badFilters')]
    public function test_invalid_list_filters_are_rejected(string $key, mixed $value): void
    {
        $this->getJson(route('warehouses.index', [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public function test_create_and_edit_preserve_local_list_context_and_do_not_change_stock(): void
    {
        $filters = ['search' => 'Alpha', 'warehouse_id' => $this->warehouse->warehouse_id, 'stock' => 'stocked', 'sort_by' => 'location', 'sort_dir' => 'desc', 'per_page' => 25, 'page' => 2];
        $context = WarehouseListContext::encode($filters);
        $url = route('warehouses.index', $filters);
        $this->get(route('warehouses.create', ['context' => $context]))->assertOk()->assertViewHas('returnUrl', $url);
        $this->post(route('warehouses.store'), ['warehouse_name' => '  Új raktár  ', 'location' => '  Győr  ', 'context' => $context, 'warehouse_id' => 999])->assertRedirect($url);
        $new = Warehouse::where('warehouse_name', 'Új raktár')->sole();
        $this->assertSame('Győr', $new->location);
        $this->assertNotSame(999, $new->warehouse_id);
        Stock::create(['warehouse_id' => $new->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 9]);
        $before = Stock::first()->toArray();
        $this->get(route('warehouses.edit', ['warehouse' => $new, 'context' => $context]))->assertOk()->assertViewHas('returnUrl', $url);
        $this->put(route('warehouses.update', $new), ['warehouse_name' => 'Frissített', 'location' => 'Szeged', 'context' => $context])->assertRedirect($url);
        $this->assertSame($before, Stock::first()->toArray());
        $this->assertDatabaseHas('warehouses', ['warehouse_id' => $new->warehouse_id, 'warehouse_name' => 'Frissített', 'location' => 'Szeged']);
    }

    public static function invalidFields(): array
    {
        return [['warehouse_name', ''], ['warehouse_name', str_repeat('x', 101)], ['warehouse_name', ['bad']], ['location', ''], ['location', str_repeat('x', 256)], ['location', ['bad']]];
    }

    #[DataProvider('invalidFields')]
    public function test_both_write_endpoints_validate_field_types_and_lengths(string $key, mixed $value): void
    {
        $payload = ['warehouse_name' => 'Érvényes', 'location' => 'Érvényes cím', $key => $value];
        $this->postJson(route('warehouses.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->putJson(route('warehouses.update', $this->warehouse), $payload)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertSame(1, Warehouse::count());
        $this->assertSame('Alpha raktár', $this->warehouse->fresh()->warehouse_name);
    }

    public function test_validation_keeps_the_other_field_and_renders_errors_without_unescaped_html(): void
    {
        $url = route('warehouses.create');
        $this->from($url)->post(route('warehouses.store'), ['warehouse_name' => '', 'location' => '<script>alert(1)</script>'])->assertRedirect($url)->assertSessionHasErrors('warehouse_name');
        $this->get($url)->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public static function dependencies(): array { return [['stock'], ['zero_stock'], ['movement'], ['outgoing'], ['incoming'], ['origin'], ['destination']]; }

    #[DataProvider('dependencies')]
    public function test_any_linked_business_record_blocks_deletion_even_with_zero_stock(string $dependency): void
    {
        $other = Warehouse::create(['warehouse_name' => 'Másik', 'location' => 'Cím']);
        if (in_array($dependency, ['stock', 'zero_stock'])) Stock::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => $dependency === 'stock' ? 5 : 0]);
        if ($dependency === 'movement') InventoryMovement::create(['warehouse_id' => $this->warehouse->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 1, 'type' => 'in', 'user_id' => $this->actor->id]);
        if (in_array($dependency, ['outgoing', 'incoming'])) InventoryTransfer::create(['product_id' => $this->product->product_id, 'quantity' => 1, 'user_id' => $this->actor->id, 'from_warehouse_id' => $dependency === 'outgoing' ? $this->warehouse->warehouse_id : $other->warehouse_id, 'to_warehouse_id' => $dependency === 'incoming' ? $this->warehouse->warehouse_id : $other->warehouse_id]);
        if (in_array($dependency, ['origin', 'destination'])) {
            $shipment = Shipment::create(['origin_warehouse_id' => $dependency === 'origin' ? $this->warehouse->warehouse_id : $other->warehouse_id, 'user_id' => $this->actor->id, 'reference' => 'Terv']);
            if ($dependency === 'destination') $shipment->stops()->create(['warehouse_id' => $this->warehouse->warehouse_id, 'position' => 1]);
        }
        $this->deleteJson(route('warehouses.destroy', $this->warehouse))->assertUnprocessable()->assertJsonValidationErrors('warehouse');
        $this->assertModelExists($this->warehouse);
        $this->get(route('warehouses.index'))->assertOk()->assertViewHas('warehouses', fn($rows) => $rows->firstWhere('warehouse_id', $this->warehouse->warehouse_id)->is_in_use);
    }

    public function test_an_unused_warehouse_can_be_deleted_and_returns_to_its_list(): void
    {
        $context = WarehouseListContext::encode(['search' => 'Alpha']);
        $this->delete(route('warehouses.destroy', $this->warehouse), ['context' => $context])->assertRedirect(route('warehouses.index', ['search' => 'Alpha']))->assertSessionHas('success');
        $this->assertModelMissing($this->warehouse);
    }

    public function test_foreign_key_guard_is_reported_as_validation_error_when_a_dependency_appears_late(): void
    {
        Warehouse::deleting(fn($warehouse) => Stock::create(['warehouse_id' => $warehouse->warehouse_id, 'product_id' => $this->product->product_id, 'quantity' => 1]));
        try {
            $this->deleteJson(route('warehouses.destroy', $this->warehouse))->assertUnprocessable()->assertJsonValidationErrors('warehouse');
            $this->assertModelExists($this->warehouse);
            $this->assertDatabaseCount('stocks', 0);
        } finally { Warehouse::flushEventListeners(); }
    }

    public function test_deletion_removes_its_exact_filter_and_pagination_but_keeps_other_list_options(): void
    {
        $filters = ['warehouse_id' => $this->warehouse->warehouse_id, 'stock' => 'empty', 'sort_by' => 'location', 'sort_dir' => 'desc', 'per_page' => 25, 'page' => 2];
        $context = WarehouseListContext::encode($filters);
        unset($filters['warehouse_id'], $filters['page']);
        $response = $this->delete(route('warehouses.destroy', $this->warehouse), ['context' => $context]);
        $response->assertRedirect(route('warehouses.index', $filters))->assertSessionHas('success');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Raktár törölve.');
        $this->assertModelMissing($this->warehouse);
    }

    public function test_context_cannot_redirect_to_an_external_url_or_keep_unapproved_parameters(): void
    {
        foreach (['broken', ['bad'], str_repeat('x', 5000)] as $token) $this->get(route('warehouses.create', ['context' => $token]))->assertOk()->assertViewHas('returnUrl', route('warehouses.index'));
        $token = Crypt::encryptString(json_encode(['search' => 'Alpha', 'redirect' => 'https://example.com', 'stock' => ['bad']]));
        $this->get(route('warehouses.create', ['context' => $token]))->assertViewHas('returnUrl', route('warehouses.index', ['search' => 'Alpha']));
    }

    public static function allowedRoles(): array { return [['admin'], ['manager']]; }
    #[DataProvider('allowedRoles')]
    public function test_admin_and_manager_can_access_management_forms(string $role): void
    {
        $this->actor->role = $role; $this->actingAs($this->actor);
        foreach (['warehouses.index', 'warehouses.create', 'warehouses.edit'] as $route) $this->get(route($route, $route === 'warehouses.edit' ? $this->warehouse : []))->assertOk();
        $this->post(route('warehouses.store'), ['warehouse_name' => 'Engedélyezett', 'location' => 'Cím'])->assertRedirect(route('warehouses.index'));
        $created = Warehouse::where('warehouse_name', 'Engedélyezett')->sole();
        $this->put(route('warehouses.update', $created), ['warehouse_name' => 'Módosítva', 'location' => 'Új cím'])->assertRedirect(route('warehouses.index'));
        $this->assertSame('Módosítva', $created->fresh()->warehouse_name);
        $this->delete(route('warehouses.destroy', $created))->assertRedirect(route('warehouses.index'));
        $this->assertModelMissing($created);
    }

    public static function deniedRoles(): array { return [[null], ['worker'], ['invalid']]; }
    #[DataProvider('deniedRoles')]
    public function test_guests_workers_and_invalid_roles_cannot_read_or_modify_warehouses(?string $role): void
    {
        if ($role === null) auth()->logout(); else { $this->actor->role = $role; $this->actingAs($this->actor); }
        $status = $role === null ? 401 : 403;
        foreach (['warehouses.index', 'warehouses.create', 'warehouses.edit'] as $route) $this->getJson(route($route, $route === 'warehouses.edit' ? $this->warehouse : []))->assertStatus($status);
        $this->postJson(route('warehouses.store'), ['warehouse_name' => 'Tiltott', 'location' => 'Tiltott'])->assertStatus($status);
        $this->putJson(route('warehouses.update', $this->warehouse), ['warehouse_name' => 'Tiltott', 'location' => 'Tiltott'])->assertStatus($status);
        $this->deleteJson(route('warehouses.destroy', $this->warehouse))->assertStatus($status);
        $this->assertModelExists($this->warehouse);
    }

    public function test_unknown_warehouse_returns_not_found(): void
    {
        $this->get(route('warehouses.edit', 999999))->assertNotFound();
        $this->put(route('warehouses.update', 999999), ['warehouse_name' => 'Név', 'location' => 'Cím'])->assertNotFound();
        $this->delete(route('warehouses.destroy', 999999))->assertNotFound();
    }

    public static function invalidHtmlFilters(): array { return [['warehouse_id', 999999], ['sort_by', 'invalid'], ['search', ['bad']]]; }

    #[DataProvider('invalidHtmlFilters')]
    public function test_invalid_html_filter_recovers_to_a_usable_list_instead_of_redirecting_to_itself(string $key, mixed $value): void
    {
        $url = route('warehouses.index', [$key => $value]);
        $response = $this->from($url)->get($url)->assertRedirect(route('warehouses.index'))->assertSessionHasErrors('filters');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('A keresési feltételek egy része már nem érvényes.');
    }

    public function test_exact_warehouse_filter_distinguishes_duplicate_names_and_keeps_other_filters(): void
    {
        $duplicate = Warehouse::create(['warehouse_name' => $this->warehouse->warehouse_name, 'location' => 'Másik cím']);
        $this->get(route('warehouses.index', ['warehouse_id' => $duplicate->warehouse_id]))->assertOk()
            ->assertViewHas('selectedWarehouse', fn($selected) => $selected->is($duplicate))
            ->assertViewHas('warehouses', fn($rows) => $rows->total() === 1 && $rows->first()->is($duplicate));
        $this->get(route('warehouses.index', ['warehouse_id' => $duplicate->warehouse_id, 'stock' => 'stocked']))
            ->assertViewHas('warehouses', fn($rows) => $rows->total() === 0);
        $this->get(route('warehouses.index', ['warehouse_id' => $duplicate->warehouse_id, 'search' => 'Budapest']))
            ->assertViewHas('warehouses', fn($rows) => $rows->total() === 0);
    }
}
