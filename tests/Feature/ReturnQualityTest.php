<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, ReturnQualityAction, Stock, User, Warehouse};
use App\Services\{InventoryService, ShipmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnQualityTest extends TestCase
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
        $category = Category::create(['category_name' => 'Minősítés']);
        $this->product = Product::create(['product_name' => 'Minősítendő termék', 'sku' => 'QUALITY-001', 'price' => 100, 'category_id' => $category->category_id]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Minősítő raktár', 'location' => 'Test']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 10]);
    }

    private function movement(string $type = 'out', int $quantity = 5): InventoryMovement
    {
        return app(InventoryService::class)->recordMovement(['product_id' => $this->product->product_id,
            'warehouse_id' => $this->warehouse->warehouse_id, 'user_id' => $this->user->id, 'type' => $type, 'quantity' => $quantity]);
    }

    private function returned(int $quantity = 5): InventoryMovement
    {
        return app(InventoryService::class)->recordReturn($this->movement('out', $quantity), [
            'user_id' => $this->user->id, 'quantity' => $quantity, 'return_reason' => 'Vizsgálatra visszahozva']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['request_key' => (string)Str::uuid(), 'action' => 'release', 'quantity' => 1, 'reason' => 'Ellenőrizve, megfelelő'], $overrides);
    }

    private function process(InventoryMovement $return, array $overrides = [])
    {
        return $this->postJson(route('movements.quality.store', $return), $this->data($overrides));
    }

    private function assertBalance(int $physical, int $quarantined, int $available): void
    {
        $stock = Stock::sole();
        $this->assertSame($physical, (int)$stock->quantity);
        $this->assertSame($quarantined, (int)$stock->quarantined_quantity);
        $this->assertSame($available, $stock->available_quantity);
    }

    public function test_new_customer_return_is_physical_stock_but_not_available_stock(): void
    {
        $return = $this->returned();
        $this->assertBalance(10, 5, 5);
        $this->assertSame(5, $return->quarantined_quantity);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->getJson(route('movements.stock', ['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id]))
            ->assertOk()->assertJson(['quantity' => 5, 'physical_quantity' => 10, 'quarantined_quantity' => 5]);
    }

    public function test_partial_release_and_scrap_preserve_original_and_have_balanced_audit_records(): void
    {
        $return = $this->returned();
        $original = $return->returnOf->getAttributes();
        $returned = $return->fresh()->getAttributes();
        $this->process($return, ['quantity' => 2])->assertRedirect();
        $this->assertBalance(10, 3, 7);
        $release = ReturnQualityAction::sole();
        $this->assertSame('release', $release->action);
        $this->assertNull($release->movement_id);
        $this->assertSame([10, 10, 5, 3], array_map('intval', [$release->stock_before, $release->stock_after, $release->quarantined_before, $release->quarantined_after]));
        $this->process($return, ['action' => 'scrap', 'quantity' => 3, 'reason' => 'Törött alkatrészek'])->assertRedirect();
        $this->assertBalance(7, 0, 7);
        $scrap = ReturnQualityAction::where('action', 'scrap')->sole();
        $this->assertSame([10, 7, 3, 0], array_map('intval', [$scrap->stock_before, $scrap->stock_after, $scrap->quarantined_before, $scrap->quarantined_after]));
        $movement = InventoryMovement::findOrFail($scrap->movement_id);
        $this->assertSame('out', $movement->type);
        $this->assertSame(3, $movement->quantity);
        $this->assertSame((int)$return->id, (int)$movement->scrap_of_id);
        $this->assertNull($movement->return_of_id);
        $this->assertSame($this->product->product_id, $movement->product_id);
        $this->assertSame($this->warehouse->warehouse_id, $movement->warehouse_id);
        $this->assertSame($returned, $return->fresh()->getAttributes());
        $this->assertSame($original, $return->returnOf->fresh()->getAttributes());
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->get(route('movements.show', $return))->assertOk()->assertSee('Törött alkatrészek');
        $this->get(route('movements.return.create', $movement))->assertNotFound();
        $this->postJson(route('movements.return.store', $movement), ['request_key' => (string)Str::uuid(), 'quantity' => 1, 'return_reason' => 'Hamis visszáru'])
            ->assertUnprocessable()->assertJsonValidationErrors('movement');
    }

    public function test_quality_actions_cannot_process_more_than_the_remaining_quantity(): void
    {
        $return = $this->returned();
        $this->process($return, ['quantity' => 3])->assertRedirect();
        $data = $this->data(['action' => 'scrap', 'quantity' => 3]);
        $this->process($return, $data)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertBalance(10, 2, 8);
        $this->assertDatabaseCount('return_quality_actions', 1);
        $this->process($return, array_replace($data, ['quantity' => 2]))->assertRedirect();
        $this->process($return)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertBalance(8, 0, 8);
        $this->assertDatabaseCount('return_quality_actions', 2);
    }

    public function test_each_return_has_an_independent_remaining_quality_quantity(): void
    {
        $original = $this->movement();
        $service = app(InventoryService::class);
        $first = $service->recordReturn($original, ['user_id' => $this->user->id, 'quantity' => 2, 'return_reason' => 'Első']);
        $second = $service->recordReturn($original, ['user_id' => $this->user->id, 'quantity' => 3, 'return_reason' => 'Második']);
        $this->process($first, ['quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->process($second, ['action' => 'scrap', 'quantity' => 3])->assertRedirect();
        $this->assertBalance(7, 2, 5);
        $this->process($first, ['quantity' => 2])->assertRedirect();
        $this->assertBalance(7, 0, 7);
    }

    public static function actions(): array { return [['release'], ['scrap']]; }

    #[DataProvider('actions')]
    public function test_identical_replay_after_full_processing_is_read_only_and_changed_payload_is_rejected(string $action): void
    {
        $return = $this->returned();
        $data = $this->data(['action' => $action, 'quantity' => 5]);
        $this->process($return, $data)->assertRedirect();
        $saved = ReturnQualityAction::sole()->getAttributes();
        $stock = Stock::sole()->getAttributes();
        $this->travel(1)->hour();
        $this->process($return, array_replace($data, ['request_key' => strtoupper($data['request_key'])]))->assertRedirect();
        $this->assertSame($saved, ReturnQualityAction::sole()->getAttributes());
        $this->assertSame($stock, Stock::sole()->getAttributes());
        foreach (['quantity' => 4, 'reason' => 'Más vizsgálat', 'action' => $action === 'release' ? 'scrap' : 'release'] as $field => $value) {
            $this->process($return, array_replace($data, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        }
        $this->assertDatabaseCount('return_quality_actions', 1);
        $this->assertDatabaseCount('inventory_movements', $action === 'scrap' ? 3 : 2);
    }

    public function test_same_quality_key_cannot_be_reused_for_another_return_and_users_share_the_quantity_limit(): void
    {
        $first = $this->returned(2);
        $second = $this->returned(2);
        $data = $this->data();
        $this->process($first, $data)->assertRedirect();
        $this->process($second, $data)->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->process($first, $data)->assertRedirect();
        $this->process($first)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('return_quality_actions', 2);
        $this->assertBalance(10, 2, 8);
    }

    public static function invalidInputs(): array
    {
        return [['action', 'other'], ['action', []], ['action', null], ['quantity', 0], ['quantity', -1], ['quantity', 1.5], ['quantity', 'bad'],
            ['quantity', []], ['quantity', null], ['quantity', 2147483648], ['reason', ''], ['reason', '   '], ['reason', "\u{200B}"], ['reason', []],
            ['reason', null], ['reason', str_repeat('á', 501)], ['request_key', 'not-a-uuid'], ['request_key', []]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_quality_requests_leave_every_table_unchanged(string $field, mixed $value): void
    {
        $return = $this->returned();
        $this->process($return, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertBalance(10, 5, 5);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_whitespace_is_trimmed_and_zero_is_a_valid_reason(): void
    {
        $return = $this->returned();
        $this->process($return, ['reason' => " \t0\n "])->assertRedirect();
        $this->assertSame('0', ReturnQualityAction::sole()->reason);
    }

    public function test_forged_actor_source_balances_and_stock_fields_are_ignored(): void
    {
        $return = $this->returned();
        $this->process($return, ['user_id' => 99999, 'return_movement_id' => 99999, 'product_id' => 99999, 'warehouse_id' => 99999,
            'stock_before' => 99999, 'stock_after' => 99999, 'quarantined_before' => 99999, 'quarantined_after' => 99999, 'quantity' => 2])->assertRedirect();
        $record = ReturnQualityAction::sole();
        $this->assertSame($this->user->id, (int)$record->user_id);
        $this->assertSame($return->id, (int)$record->return_movement_id);
        $this->assertSame([10, 10, 5, 3], array_map('intval', [$record->stock_before, $record->stock_after, $record->quarantined_before, $record->quarantined_after]));
        $this->assertBalance(10, 3, 7);
    }

    public function test_ordinary_movements_supplier_returns_and_legacy_customer_returns_cannot_be_quality_processed(): void
    {
        $ordinary = $this->movement('in', 5);
        $supplierReturn = app(InventoryService::class)->recordReturn($ordinary, ['user_id' => $this->user->id, 'quantity' => 2, 'return_reason' => 'Beszállítónak']);
        $legacy = InventoryMovement::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'in', 'quantity' => 2, 'return_of_id' => $this->movement()->id, 'return_reason' => 'Régi, szabad visszáru']);
        foreach ([$ordinary, $supplierReturn, $legacy] as $source) {
            $this->process($source)->assertUnprocessable();
        }
        $this->assertSame(0, (int)$supplierReturn->fresh()->quarantined_quantity);
        $this->assertSame(0, (int)$legacy->fresh()->quarantined_quantity);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertSame(0, (int)Stock::sole()->quarantined_quantity);
    }

    public function test_quality_history_escapes_reasons_in_the_detail_page(): void
    {
        $return = $this->returned();
        $reason = '<script>alert("quality")</script>';
        $this->process($return, ['reason' => $reason])->assertRedirect();
        $this->get(route('movements.show', $return))->assertOk()->assertSee($reason)->assertDontSee($reason, false);
    }

    public function test_consumption_cannot_spend_quarantine_and_a_release_makes_only_the_released_part_available(): void
    {
        $return = $this->returned();
        try {
            $this->movement('out', 6);
            $this->fail('Quarantined goods must not be issued.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('quantity', $exception->errors()); }
        $this->assertBalance(10, 5, 5);
        $this->process($return, ['quantity' => 2])->assertRedirect();
        $this->movement('out', 7);
        $this->assertBalance(3, 3, 0);
        $this->process($return, ['action' => 'scrap', 'quantity' => 3])->assertRedirect();
        $this->assertBalance(0, 0, 0);
    }

    public function test_supplier_return_cannot_consume_quarantined_goods(): void
    {
        $receipt = $this->movement('in', 6);
        $this->returned();
        $this->movement('out', 11);
        $this->assertBalance(5, 5, 0);
        try {
            app(InventoryService::class)->recordReturn($receipt, ['user_id' => $this->user->id, 'quantity' => 1, 'return_reason' => 'Beszállítónak']);
            $this->fail('Supplier return must not consume quarantined goods.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('quantity', $exception->errors()); }
        $this->assertBalance(5, 5, 0);
        $this->assertSame(0, $receipt->returns()->count());
    }

    public function test_transfer_limit_uses_available_stock_and_cancel_restores_available_stock_only(): void
    {
        $this->returned();
        $destination = Warehouse::create(['warehouse_name' => 'Célraktár', 'location' => 'Test']);
        $data = ['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->warehouse->warehouse_id,
            'to_warehouse_id' => $destination->warehouse_id, 'user_id' => $this->user->id, 'quantity' => 6];
        try {
            app(InventoryService::class)->startTransfer($data);
            $this->fail('Transfer must not consume quarantined goods.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('quantity', $exception->errors()); }
        $transfer = app(InventoryService::class)->startTransfer(array_replace($data, ['quantity' => 5]));
        $this->assertBalance(5, 5, 0);
        app(InventoryService::class)->finalizeTransfer($transfer, false);
        $this->assertBalance(10, 5, 5);
    }

    public function test_multistop_shipment_rolls_back_earlier_items_when_combined_quantity_exceeds_available_stock(): void
    {
        $this->returned();
        $one = Warehouse::create(['warehouse_name' => 'Első cél', 'location' => 'Test']);
        $two = Warehouse::create(['warehouse_name' => 'Második cél', 'location' => 'Test']);
        $shipment = app(ShipmentService::class)->save(['origin_warehouse_id' => $this->warehouse->warehouse_id, 'reference' => 'QUALITY-SHIPMENT',
            'stops' => [ ['warehouse_id' => $one->warehouse_id, 'items' => [['product_id' => $this->product->product_id, 'quantity' => 3]]],
                ['warehouse_id' => $two->warehouse_id, 'items' => [['product_id' => $this->product->product_id, 'quantity' => 3]]] ]], $this->user->id);
        try {
            app(ShipmentService::class)->start($shipment, $this->user->id, $shipment->version);
            $this->fail('Shipment must not consume quarantined goods.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('quantity', $exception->errors()); }
        $this->assertBalance(10, 5, 5);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'status' => 'draft', 'version' => 1]);
        $this->assertDatabaseCount('shipment_items', 2);
        $this->assertDatabaseHas('shipment_items', ['transfer_id' => null]);
    }

    #[DataProvider('actions')]
    public function test_error_after_quality_audit_insert_rolls_back_stock_scrap_and_idempotency_record(string $action): void
    {
        $return = $this->returned();
        ReturnQualityAction::updated(function () { throw new \RuntimeException('injected quality failure'); });
        try {
            app(InventoryService::class)->processReturnQuality($return, ['user_id' => $this->user->id, 'action' => $action, 'quantity' => 2,
                'reason' => 'Teszt'], (string)Str::uuid());
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) { $this->assertSame('injected quality failure', $exception->getMessage()); }
        finally { ReturnQualityAction::flushEventListeners(); }
        $this->assertBalance(10, 5, 5);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public static function roles(): array { return [['admin'], ['manager'], ['worker']]; }

    #[DataProvider('roles')]
    public function test_all_inventory_operator_roles_can_process_a_return(string $role): void
    {
        $return = $this->returned();
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);
        $this->process($return)->assertRedirect();
        $this->assertSame($actor->id, (int)ReturnQualityAction::sole()->user_id);
    }

    public function test_guest_cannot_modify_return_quality(): void
    {
        $return = $this->returned();
        auth()->logout();
        $this->post(route('movements.quality.store', $return), $this->data())->assertRedirect(route('login'));
        $this->assertBalance(10, 5, 5);
        $this->assertDatabaseCount('return_quality_actions', 0);
    }

    public function test_quality_operator_cannot_be_deleted_from_user_management_or_their_profile(): void
    {
        $return = $this->returned();
        $operator = User::factory()->create(['role' => 'worker', 'password' => 'password']);
        $this->actingAs($operator);
        $this->process($return)->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson(route('users.destroy', $operator))->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->actingAs($operator);
        $this->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertAuthenticatedAs($operator);
        $this->assertDatabaseHas('users', ['id' => $operator->id]);
        $this->assertDatabaseCount('return_quality_actions', 1);
    }

    public function test_an_already_deleted_quality_actor_is_rejected_without_stock_or_audit_changes(): void
    {
        $return = $this->returned();
        $operator = User::factory()->create(['role' => 'worker']);
        $operatorId = $operator->id;
        $operator->delete();
        try {
            app(InventoryService::class)->processReturnQuality($return, ['user_id' => $operatorId, 'action' => 'release',
                'quantity' => 2, 'reason' => 'Időközben törölt felhasználó'], (string)Str::uuid());
            $this->fail('A deleted actor must not process a return.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }
        $this->assertBalance(10, 5, 5);
        $this->assertDatabaseCount('return_quality_actions', 0);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseMissing('users', ['id' => $operatorId]);
    }

    public function test_quality_action_committed_between_delete_precheck_and_delete_is_reported_as_validation_error(): void
    {
        $return = $this->returned();
        $operator = User::factory()->create(['role' => 'worker']);
        $dispatcher = User::getEventDispatcher();
        // Preserve the model guard and restore every original listener after this test.
        User::setEventDispatcher(clone $dispatcher);
        $injected = false;
        User::deleting(function (User $candidate) use ($operator, $return, &$injected) {
            if ($injected || $candidate->id !== $operator->id) return;
            $injected = true;
            // This listener runs after the booted precheck but before the actual DELETE.
            app(InventoryService::class)->processReturnQuality($return, ['user_id' => $operator->id,
                'action' => 'release', 'quantity' => 2, 'reason' => 'A törlés előellenőrzése közben elkészült minősítés'], (string)Str::uuid());
        });
        try {
            $operator->delete();
            $this->fail('The FK-protected quality actor must not be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        } finally {
            User::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($injected);
        $this->assertDatabaseHas('users', ['id' => $operator->id]);
        $this->assertBalance(10, 3, 7);
        $this->assertDatabaseCount('return_quality_actions', 1);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('return_quality_actions', ['return_movement_id' => $return->id, 'user_id' => $operator->id,
            'action' => 'release', 'quantity' => 2, 'stock_before' => 10, 'stock_after' => 10, 'quarantined_before' => 5, 'quarantined_after' => 3]);
    }

    public function test_blocked_profile_deletion_keeps_the_actor_authenticated_and_uses_the_profile_error_bag(): void
    {
        $return = $this->returned();
        $operator = User::factory()->create(['role' => 'worker', 'password' => 'password']);
        $this->actingAs($operator);
        $this->process($return)->assertRedirect();
        $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))->assertSessionHasErrorsIn('userDeletion', 'user');
        $this->assertAuthenticatedAs($operator);
        $this->assertDatabaseHas('users', ['id' => $operator->id]);
        $this->assertDatabaseCount('return_quality_actions', 1);
    }

    public function test_quality_history_pagination_recovers_from_an_out_of_range_page_and_keeps_the_context(): void
    {
        $return = $this->returned(10);
        for ($i=0; $i<9; $i++) $this->process($return)->assertRedirect();
        $this->get(route('movements.show', ['movement' => $return, 'quality_page' => 999, 'context' => 'context-test']))
            ->assertRedirect(route('movements.show', ['movement' => $return, 'context' => 'context-test']));
        $this->get(route('movements.show', $return))->assertOk()->assertViewHas('qualityRemaining', 1);
    }
}
