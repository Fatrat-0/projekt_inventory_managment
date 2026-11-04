<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Partner, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use App\Support\MovementListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovementReturnTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Visszáru']);
        $this->product = Product::create(['product_name' => 'Visszáru termék', 'sku' => 'RETURN', 'price' => 100, 'category_id' => $category->category_id]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Visszáru raktár', 'location' => 'Test']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 10]);
    }

    private function original(string $type = 'out', int $quantity = 5): InventoryMovement
    {
        $partner = Partner::create(['name' => 'Eredeti partner', 'type' => $type === 'in' ? 'supplier' : 'customer']);
        return app(InventoryService::class)->recordMovement(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'partner_id' => $partner->id, 'type' => $type, 'quantity' => $quantity, 'reference' => 'EREDETI']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['request_key' => (string)Str::uuid(), 'quantity' => 1, 'return_reason' => 'Hibás termék', 'reference' => 'VISSZA-001'], $overrides);
    }

    private function postReturn(InventoryMovement $original, array $data = [])
    {
        return $this->postJson(route('movements.return.store', $original), $this->data($data));
    }

    public static function directions(): array { return [['in'], ['out']]; }

    #[DataProvider('directions')]
    public function test_partial_and_full_returns_reverse_stock_keep_original_and_link_history(string $type): void
    {
        $original = $this->original($type);
        $before = $original->fresh()->getAttributes();
        $this->postReturn($original, ['quantity' => 2])->assertRedirect();
        $return = $original->returns()->sole();
        $this->assertSame($type === 'in' ? 'out' : 'in', $return->type);
        $this->assertSame($original->partner_id, $return->partner_id);
        $this->assertSame($original->product_id, $return->product_id);
        $this->assertSame($original->warehouse_id, $return->warehouse_id);
        $this->assertSame($type === 'in' ? 13 : 7, Stock::sole()->quantity);
        $this->assertSame($type === 'in' ? 15 : 5, $return->stock_before);
        $this->assertSame($type === 'in' ? 13 : 7, $return->stock_after);
        $this->get(route('movements.show', $original))->assertOk()->assertViewHas('remaining', 3)->assertViewHas('returned', 2);
        $this->get(route('movements.show', $return))->assertOk()->assertSee('Visszáru')->assertSee($original->movement_number);
        $this->postReturn($original, ['quantity' => 3])->assertRedirect();
        $this->assertSame(10, Stock::sole()->quantity);
        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertSame(5, (int)$original->returns()->sum('quantity'));
        $this->get(route('movements.return.create', $original))->assertOk()->assertSee('már teljes egészében')->assertDontSee('name="request_key"', false);
    }

    public static function invalidInputs(): array
    {
        return [['quantity', 0], ['quantity', -1], ['quantity', 1.5], ['quantity', 'bad'], ['quantity', []], ['quantity', null], ['quantity', 2147483648],
            ['return_reason', ''], ['return_reason', '   '], ['return_reason', "\u{200B}"], ['return_reason', null], ['return_reason', ['bad']], ['return_reason', str_repeat('á', 501)],
            ['reference', ['bad']], ['reference', str_repeat('x', 256)], ['request_key', 'bad'], ['request_key', ['bad']]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_never_changes_stock_history_or_claims(string $field, mixed $value): void
    {
        $original = $this->original();
        $this->postReturn($original, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(5, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
    }

    public function test_cumulative_limit_rejects_stale_quantity_and_preserves_remaining_stock(): void
    {
        $original = $this->original();
        $this->get(route('movements.return.create', $original))->assertViewHas('remaining', 5);
        $this->postReturn($original, ['quantity' => 3])->assertRedirect();
        $this->postReturn($original, ['quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(8, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
        $this->postReturn($original, ['quantity' => 2])->assertRedirect();
        $this->postReturn($original)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(10, Stock::sole()->quantity);
    }

    public function test_replay_after_full_return_reuses_record_and_rejects_changed_payload_and_operation(): void
    {
        $original = $this->original('out', 2);
        $data = $this->data(['quantity' => 2]);
        $this->postReturn($original, $data)->assertRedirect();
        $record = $original->returns()->sole()->getAttributes();
        $this->travel(1)->hour();
        $this->postReturn($original, $data)->assertRedirect();
        $this->assertSame($record, $original->returns()->sole()->getAttributes());
        foreach (['quantity' => 1, 'return_reason' => 'Más indok', 'reference' => 'Más bizonylat'] as $field => $value) {
            $this->postReturn($original, array_replace($data, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        }
        $this->postJson('/movements', ['request_key' => $data['request_key'], 'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'type' => 'in', 'quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(10, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
    }

    public function test_same_key_cannot_target_another_original_and_each_user_still_shares_the_quantity_limit(): void
    {
        $original = $this->original('in', 3);
        $other = $this->original('in', 1);
        $data = $this->data();
        $this->postReturn($original, $data)->assertRedirect();
        $this->postReturn($other, $data)->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->postReturn($original, $data)->assertRedirect();
        $this->postReturn($original, ['quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(2, (int)$original->returns()->sum('quantity'));
    }

    public function test_supplier_return_checks_current_stock_and_failed_request_key_can_be_retried(): void
    {
        $original = $this->original('in');
        Stock::sole()->update(['quantity' => 1]);
        $data = $this->data(['quantity' => 2]);
        $this->postReturn($original, $data)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(1, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
        Stock::sole()->update(['quantity' => 2]);
        $this->postReturn($original, $data)->assertRedirect();
        $this->assertSame(0, Stock::sole()->quantity);
    }

    public function test_customer_return_cannot_overflow_stock(): void
    {
        $original = $this->original();
        Stock::sole()->update(['quantity' => 2147483647]);
        $this->postReturn($original)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(2147483647, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
    }

    public function test_archived_renamed_and_changed_role_partner_can_return_original_trade(): void
    {
        $original = $this->original();
        $original->partner->forceFill(['name' => 'Új név', 'type' => 'supplier', 'archived_at' => now()])->save();
        $this->postReturn($original)->assertRedirect();
        $return = $original->returns()->sole();
        $this->assertSame($original->partner_id, $return->partner_id);
        $this->assertSame('Új név', $return->partner_name);
        $this->assertSame('Eredeti partner', $original->fresh()->partner_name);
    }

    public function test_legacy_movement_without_partner_or_audit_balance_can_have_a_return(): void
    {
        $original = InventoryMovement::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'quantity' => 4, 'type' => 'out']);
        $this->postReturn($original, ['quantity' => 2])->assertRedirect();
        $return = $original->returns()->sole();
        $this->assertNull($return->partner_id);
        $this->assertNull($return->partner_name);
        $this->assertNull($original->fresh()->stock_before);
        $this->assertSame(10, $return->stock_before);
        $this->assertSame(12, $return->stock_after);
    }

    public function test_return_of_a_return_is_rejected_and_normal_creation_cannot_forge_return_metadata(): void
    {
        $original = $this->original();
        $this->postReturn($original)->assertRedirect();
        $return = $original->returns()->sole();
        $this->get(route('movements.return.create', $return))->assertNotFound();
        $this->postReturn($return)->assertUnprocessable()->assertJsonValidationErrors('movement');
        $this->postJson('/movements', ['request_key' => (string)Str::uuid(), 'product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'type' => 'in', 'quantity' => 1, 'return_of_id' => $original->id, 'return_reason' => 'Hamis visszáru'])->assertRedirect();
        $this->assertFalse(InventoryMovement::latest('id')->first()->isReturn());
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_client_cannot_change_inherited_product_warehouse_partner_direction_user_or_snapshot(): void
    {
        $original = $this->original();
        $this->postReturn($original, ['product_id' => 999, 'warehouse_id' => 999, 'partner_id' => 999, 'type' => 'out', 'user_id' => 999,
            'stock_before' => 999, 'stock_after' => 999, 'return_of_id' => 999, 'partner_name' => 'Hamis'])->assertRedirect();
        $return = $original->returns()->sole();
        $this->assertSame($original->product_id, $return->product_id);
        $this->assertSame($original->warehouse_id, $return->warehouse_id);
        $this->assertSame($original->partner_id, $return->partner_id);
        $this->assertSame($this->user->id, $return->user_id);
        $this->assertSame('in', $return->type);
        $this->assertSame(5, $return->stock_before);
        $this->assertSame(6, $return->stock_after);
    }

    public function test_error_after_return_insert_rolls_back_stock_movement_and_key_together(): void
    {
        $original = $this->original();
        InventoryMovement::created(function ($movement) { if ($movement->isReturn()) throw new \RuntimeException('return failure'); });
        try {
            app(InventoryService::class)->recordReturn($original, $this->data() + ['user_id' => $this->user->id], (string)Str::uuid());
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $e) { $this->assertSame('return failure', $e->getMessage()); }
        finally { InventoryMovement::flushEventListeners(); }
        $this->assertSame(5, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
    }

    public function test_history_is_paginated_and_list_context_survives_return_links(): void
    {
        $original = $this->original('in', 12);
        for ($i = 0; $i < 11; $i++) $this->postReturn($original)->assertRedirect();
        $context = MovementListContext::encode(['kind' => 'return', 'partner_id' => $original->partner_id, 'per_page' => 25]);
        $page = $this->get(route('movements.show', ['movement' => $original, 'context' => $context, 'returns_page' => 2]))->assertOk();
        $this->assertCount(1, $page->viewData('returnHistory'));
        $this->assertSame(1, $page->viewData('remaining'));
        $this->assertStringContainsString('kind=return', $page->viewData('returnUrl'));
        $this->get(route('movements.return.create', ['movement' => $original, 'context' => $context]))->assertOk()->assertViewHas('context', $context);
    }

    public function test_return_filter_and_reason_search_preserve_other_filters_and_include_returns_in_totals(): void
    {
        $original = $this->original();
        $this->postReturn($original, ['quantity' => 2, 'return_reason' => '100%_! hibás <script>alert(1)</script>'])->assertRedirect();
        $return = $original->returns()->sole();
        $this->get('/movements?kind=return')->assertOk()->assertViewHas('movements', fn ($p) => $p->pluck('id')->all() === [$return->id])->assertViewHas('totalIn', 2)->assertViewHas('totalOut', 0);
        $this->get('/movements?kind=regular')->assertViewHas('movements', fn ($p) => $p->pluck('id')->all() === [$original->id]);
        $this->get('/movements?kind=return&type=out')->assertViewHas('movements', fn ($p) => $p->isEmpty());
        $this->get('/movements?search=100%25_!')->assertViewHas('movements', fn ($p) => $p->pluck('id')->all() === [$return->id])->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson('/movements?kind=bad')->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->getJson('/movements?kind[]=return')->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->get(route('export.movements', ['kind' => 'return', 'partner_id' => $original->partner_id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_out_of_range_history_recovers_to_last_page_and_preserves_context(): void
    {
        $original = $this->original('in', 12);
        for ($i = 0; $i < 11; $i++) $this->postReturn($original)->assertRedirect();
        $context = MovementListContext::encode(['kind' => 'return', 'partner_id' => $original->partner_id, 'per_page' => 25]);
        $expected = route('movements.show', ['movement' => $original, 'context' => $context, 'returns_page' => 2]);
        $this->get(route('movements.show', ['movement' => $original, 'context' => $context, 'returns_page' => 999]))->assertRedirect($expected);
        $this->get($expected)->assertOk()->assertViewHas('returnHistory', fn ($p) => $p->currentPage() === 2 && $p->count() === 1)->assertViewHas('remaining', 1);
        $empty = $this->original('in', 1);
        $this->get(route('movements.show', ['movement' => $empty, 'returns_page' => 999]))->assertRedirect(route('movements.show', $empty));
    }

    public static function roles(): array { return [['admin'], ['manager'], ['worker']]; }

    #[DataProvider('roles')]
    public function test_all_inventory_roles_can_create_returns(string $role): void
    {
        $this->user->update(['role' => $role]);
        $original = $this->original();
        $this->get(route('movements.return.create', $original))->assertOk();
        $this->postReturn($original)->assertRedirect();
    }

    public function test_guests_unknown_roles_missing_records_and_unsafe_context_are_handled(): void
    {
        $original = $this->original();
        $this->get('/movements/999999/return')->assertNotFound();
        $this->postJson('/movements/999999/return', $this->data())->assertNotFound();
        $this->get(route('movements.return.create', ['movement' => $original, 'context' => ['bad']]))->assertOk()->assertViewHas('context', '');
        $this->postReturn($original, ['context' => 'https://evil.example'])->assertRedirect();
        $this->user->role = 'unknown'; $this->actingAs($this->user);
        $this->get(route('movements.return.create', $original))->assertForbidden();
        $this->postReturn($original)->assertForbidden();
        auth()->forgetGuards();
        $this->get(route('movements.return.create', $original))->assertRedirect(route('login'));
        $this->postReturn($original)->assertUnauthorized();
    }

    public function test_direct_service_call_rejects_invalid_quantity_and_original_cannot_be_deleted(): void
    {
        $original = $this->original();
        try {
            app(InventoryService::class)->recordReturn($original, ['quantity' => -1, 'return_reason' => 'Indok', 'user_id' => $this->user->id]);
            $this->fail('Expected validation failure.');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('quantity', $e->errors()); }
        $this->assertSame(5, Stock::sole()->quantity);
        $this->postReturn($original)->assertRedirect();
        try {
            DB::table('inventory_movements')->where('id', $original->id)->delete();
            $this->fail('Expected foreign key protection.');
        } catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('FOREIGN KEY', $e->getMessage()); }
        $this->assertModelExists($original);
    }

    public function test_demo_seeder_creates_both_return_directions_and_preserves_user_changes_on_repeat(): void
    {
        $this->user->update(['email' => 'worker@raktar.hu']);
        $this->seed(\Database\Seeders\MovementReturnDemoSeeder::class);
        $product = Product::where('sku', 'DEMO-VISSZARU')->sole();
        $this->assertSame(9, Stock::where('product_id', $product->product_id)->sole()->quantity);
        $this->assertSame(3, InventoryMovement::whereNotNull('return_of_id')->count());
        $customer = Partner::where('email', 'return-customer-demo@example.test')->sole();
        $customer->forceFill(['name' => 'Átnevezett bemutató', 'email' => 'changed@example.test', 'archived_at' => now()])->save();
        $snapshot = fn () => collect(['inventory_movements', 'inventory_movement_requests', 'stocks', 'partners'])->mapWithKeys(
            fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array)$row)->all()])->all();
        $before = $snapshot();
        $this->travel(1)->hour();
        $this->seed(\Database\Seeders\MovementReturnDemoSeeder::class);
        $this->assertSame($before, $snapshot());
    }

    public function test_long_pdf_reason_is_printed_in_full_outside_the_table_and_can_be_hidden(): void
    {
        $original = $this->original();
        $reason = str_repeat('W', 500);
        $this->postReturn($original, ['return_reason' => $reason, 'reference' => str_repeat('W', 255)])->assertRedirect();
        $return = $original->returns()->with(['product', 'warehouse', 'user'])->sole();
        $options = ['type_filter' => 'all', 'show_reference' => true, 'show_partner' => true, 'show_user' => true,
            'show_warehouse' => true, 'show_summary' => true, 'show_signatures' => true];
        $render = fn () => view('pdf.movements', ['movements' => collect([$return]), 'options' => $options])->render();
        $html = $render();
        $this->assertStringContainsString('Visszáruk részletes indoklása', $html);
        $this->assertSame(1, substr_count($html, $reason));
        $this->assertStringContainsString($return->movement_number.' részletezését', $html);
        $this->assertStringContainsString($original->movement_number, $html);
        $options['show_reference'] = false;
        $html = view('pdf.movements', ['movements' => collect([$return]), 'options' => $options])->render();
        $this->assertStringNotContainsString($reason, $html);
        $this->assertStringNotContainsString('Visszáruk részletes indoklása', $html);
    }
}
