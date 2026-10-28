<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovementSafetyTest extends TestCase
{
    use RefreshDatabase;
    private Product $product;
    private Warehouse $warehouse;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['category_id' => $category->category_id, 'product_name' => 'Teszt csavar', 'sku' => 'SAFETY', 'price' => 100]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Forrás', 'location' => 'A']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 10]);
    }

    private function payload(array $data = []): array
    {
        return array_merge(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'type' => 'out', 'quantity' => 4, 'reference' => 'BIZ-001', 'request_key' => (string) Str::uuid()], $data);
    }

    public function test_identical_replay_reuses_original_movement_without_touching_stock(): void
    {
        $data = $this->payload(['quantity' => 10]);
        $this->post(route('movements.store'), $data)->assertRedirect();
        $movement = InventoryMovement::sole();
        $this->post(route('movements.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
        $this->assertSame(0, Stock::sole()->quantity);
        $this->assertSame(10, $movement->stock_before);
        $this->assertSame(0, $movement->stock_after);
        $this->assertDatabaseHas('inventory_movement_requests', ['movement_id' => $movement->id, 'user_id' => $this->user->id]);
    }

    public static function changedPayloads(): array
    {
        return [['quantity', 5], ['type', 'in'], ['reference', 'OTHER']];
    }

    #[DataProvider('changedPayloads')]
    public function test_reusing_key_with_different_payload_is_rejected(string $field, mixed $value): void
    {
        $data = $this->payload();
        $this->post(route('movements.store'), $data)->assertRedirect();
        $this->postJson(route('movements.store'), array_replace($data, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(6, Stock::sole()->quantity);
    }

    public function test_same_key_cannot_be_reused_for_a_different_product_or_warehouse(): void
    {
        $data = $this->payload();
        $this->post(route('movements.store'), $data)->assertRedirect();
        $otherProduct = Product::create(['category_id' => $this->product->category_id, 'product_name' => 'Másik', 'sku' => 'OTHER', 'price' => 100]);
        $otherWarehouse = Warehouse::create(['warehouse_name' => 'Másik', 'location' => 'B']);
        foreach ([['product_id' => $otherProduct->product_id], ['warehouse_id' => $otherWarehouse->warehouse_id]] as $change) {
            $this->postJson(route('movements.store'), array_replace($data, $change))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        }
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_fresh_key_allows_a_separate_identical_business_operation(): void
    {
        $data = $this->payload();
        $this->post(route('movements.store'), $data)->assertRedirect();
        $this->post(route('movements.store'), array_replace($data, ['request_key' => (string) Str::uuid()]))->assertRedirect();
        $this->assertEquals(2, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_conflicting_replay_offers_a_fresh_form_without_reusing_the_failed_key(): void
    {
        $data = $this->payload();
        $this->post(route('movements.store'), $data)->assertRedirect();
        $context = \App\Support\MovementListContext::encode(['search' => 'csavar']);
        $createUrl = route('movements.create', ['context' => $context]);
        $this->from($createUrl)->post(route('movements.store'), array_replace($data, ['quantity' => 5, 'context' => $context]))
            ->assertRedirect($createUrl)->assertSessionHasErrors('request_key');
        $this->get($createUrl)->assertOk()->assertSee('Új készletmozgás indítása')->assertSee($createUrl);
        // Flash data has now been consumed; following the link starts a separate operation.
        $fresh = $this->get($createUrl)->assertOk();
        $this->assertNotSame($data['request_key'], $fresh->viewData('requestKey'));
        $this->assertStringContainsString('search=csavar', $fresh->viewData('returnUrl'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertEquals(6, Stock::sole()->quantity);
    }

    public function test_request_keys_are_scoped_to_the_actor(): void
    {
        $data = $this->payload(['type' => 'in']);
        $this->post(route('movements.store'), $data)->assertRedirect();
        $secondUser = User::factory()->create(['role' => 'worker']);
        $this->actingAs($secondUser)->post(route('movements.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('inventory_movement_requests', 2);
        $this->assertEquals(18, Stock::sole()->quantity);
    }

    public function test_canonical_numeric_values_and_empty_reference_replay_identically(): void
    {
        $data = $this->payload(['reference' => null]);
        $this->post(route('movements.store'), $data)->assertRedirect();
        $this->post(route('movements.store'), array_replace($data, ['quantity' => '4', 'product_id' => (string) $this->product->product_id, 'reference' => '', 'request_key' => strtoupper($data['request_key'])]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_missing_or_malformed_request_key_is_rejected(): void
    {
        foreach ([null, 'bad', ['invalid']] as $key) {
            $this->postJson(route('movements.store'), $this->payload(['request_key' => $key]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        }
        $this->assertEquals(10, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_failed_transaction_releases_key_and_retry_can_succeed(): void
    {
        $service = app(InventoryService::class);
        $data = $this->payload();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['user_id'] = $this->user->id;
        InventoryMovement::created(fn () => throw new \RuntimeException('intentional failure'));
        try {
            $service->recordMovement($data, $key);
            $this->fail('Expected intentional failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('intentional failure', $e->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.created: '.InventoryMovement::class);
        }
        $this->assertEquals(10, Stock::sole()->quantity);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $service->recordMovement($data, $key);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
        $this->assertEquals(6, Stock::sole()->quantity);
    }

    public function test_snapshots_are_server_calculated_and_do_not_change_with_later_stock(): void
    {
        $this->post(route('movements.store'), $this->payload(['stock_before' => 999, 'stock_after' => 999]))->assertRedirect();
        $original = InventoryMovement::sole();
        $this->post(route('movements.store'), $this->payload(['quantity' => 2]))->assertRedirect();
        $this->assertSame(10, $original->fresh()->stock_before);
        $this->assertSame(6, $original->fresh()->stock_after);
        $this->assertEquals(4, Stock::sole()->quantity);
        $this->get(route('movements.show', $original))->assertOk()->assertSee($original->movement_number)->assertSee('Művelet előtt')->assertSee('10 db')->assertSee('6 db');
    }

    public function test_legacy_details_are_explicitly_unknown_and_reference_is_escaped(): void
    {
        $data = $this->payload(['reference' => '<script>alert(1)</script>']);
        unset($data['request_key']);
        $movement = InventoryMovement::create($data + ['user_id' => $this->user->id]);
        $this->get(route('movements.show', $movement))->assertOk()->assertSee('korábbi tételhez nem áll rendelkezésre')->assertSee($data['reference'])->assertDontSee($data['reference'], false);
        $this->assertNull($movement->stock_before);
    }

    public function test_details_preserve_list_context_and_unknown_id_returns_404(): void
    {
        $this->post(route('movements.store'), $this->payload())->assertRedirect();
        $movement = InventoryMovement::sole();
        $context = \App\Support\MovementListContext::encode(['search' => 'csavar', 'page' => 2]);
        $response = $this->get(route('movements.show', ['movement' => $movement, 'context' => $context]))->assertOk();
        $this->assertStringContainsString('search=csavar', $response->viewData('returnUrl'));
        $this->assertStringContainsString('page=2', $response->viewData('returnUrl'));
        $this->get(route('movements.show', 99999))->assertNotFound();
    }

    public function test_detail_requires_authentication_and_a_supported_role(): void
    {
        $this->post(route('movements.store'), $this->payload())->assertRedirect();
        $movement = InventoryMovement::sole();
        auth()->logout();
        $this->getJson(route('movements.show', $movement))->assertUnauthorized();
        $this->user->role = 'invalid';
        $this->actingAs($this->user)->get(route('movements.show', $movement))->assertForbidden();
    }

    public function test_first_receipt_stores_zero_before_and_preserves_reference_zero(): void
    {
        Stock::query()->delete();
        $this->post(route('movements.store'), $this->payload(['type' => 'in', 'quantity' => 3, 'reference' => '0']))->assertRedirect()->assertSessionHasNoErrors();
        $movement = InventoryMovement::sole();
        $this->assertSame(0, $movement->stock_before);
        $this->assertSame(3, $movement->stock_after);
        $this->assertSame('0', $movement->reference);
    }
}
