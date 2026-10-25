<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'TEST', 'price' => 100, 'category_id' => $category->category_id]);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrás', 'location' => 'A']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Cél', 'location' => 'B']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->source->warehouse_id, 'quantity' => 10]);
    }

    private function movement(array $changes = []): array
    {
        return array_merge(['product_id' => $this->product->product_id, 'warehouse_id' => $this->source->warehouse_id,
            'type' => 'out', 'quantity' => 4, 'reference' => 'TESZT-001'], $changes);
    }

    private function transfer(array $changes = []): array
    {
        return array_merge(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id,
            'to_warehouse_id' => $this->destination->warehouse_id, 'quantity' => 4], $changes);
    }

    private function quantity(Warehouse $warehouse): int
    {
        return (int) Stock::where('product_id', $this->product->product_id)->where('warehouse_id', $warehouse->warehouse_id)->value('quantity');
    }

    public static function movements(): array
    {
        return [['in', 5, 15], ['out', 4, 6], ['out', 10, 0]];
    }

    #[DataProvider('movements')]
    public function test_movement_updates_only_the_selected_stock_and_records_actor(string $type, int $quantity, int $expected): void
    {
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->destination->warehouse_id, 'quantity' => 7]);
        $other = User::factory()->create();
        $this->post(route('movements.store'), $this->movement(['type' => $type, 'quantity' => $quantity, 'user_id' => $other->id]))->assertRedirect(route('movements.index'));
        $this->assertSame($expected, $this->quantity($this->source));
        $this->assertSame(7, $this->quantity($this->destination));
        $this->assertDatabaseHas('inventory_movements', ['type' => $type, 'quantity' => $quantity, 'user_id' => $this->user->id, 'reference' => 'TESZT-001']);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_receipt_creates_missing_stock(): void
    {
        $this->post(route('movements.store'), $this->movement(['type' => 'in', 'warehouse_id' => $this->destination->warehouse_id]))->assertRedirect();
        $this->assertSame(4, $this->quantity($this->destination));
        $this->assertSame(10, $this->quantity($this->source));
    }

    public function test_insufficient_issue_is_rejected_without_creating_log(): void
    {
        $this->postJson(route('movements.store'), $this->movement(['quantity' => 11]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(10, $this->quantity($this->source));
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_issue_from_missing_stock_is_rejected(): void
    {
        $this->postJson(route('movements.store'), $this->movement(['warehouse_id' => $this->destination->warehouse_id]))->assertUnprocessable();
        $this->assertDatabaseCount('stocks', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [1.5], ['abc'], [null], [2147483648]];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_leave_both_workflows_unchanged(mixed $quantity): void
    {
        $this->postJson(route('movements.store'), $this->movement(['quantity' => $quantity]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('transfers.store'), $this->transfer(['quantity' => $quantity]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(10, $this->quantity($this->source));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_unknown_references_invalid_type_and_same_warehouse_are_rejected(): void
    {
        $this->postJson(route('movements.store'), $this->movement(['product_id' => 99999, 'warehouse_id' => 99999, 'type' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['product_id', 'warehouse_id', 'type']);
        $this->postJson(route('transfers.store'), $this->transfer(['product_id' => 99999, 'from_warehouse_id' => 99999, 'to_warehouse_id' => 99998]))->assertUnprocessable()->assertJsonValidationErrors(['product_id', 'from_warehouse_id', 'to_warehouse_id']);
        $this->postJson(route('transfers.store'), $this->transfer(['to_warehouse_id' => $this->source->warehouse_id]))->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->assertSame(10, $this->quantity($this->source));
    }

    public static function endings(): array
    {
        return [['complete', 'completed', 6, 4], ['cancel', 'cancelled', 10, 0]];
    }

    #[DataProvider('endings')]
    public function test_transfer_conserves_stock_and_cannot_be_finalized_twice(string $action, string $status, int $source, int $destination): void
    {
        $this->post(route('transfers.store'), $this->transfer(['user_id' => 99999, 'status' => 'completed']))->assertRedirect(route('transfers.index'));
        $transfer = InventoryTransfer::sole();
        $this->assertSame('pending', $transfer->status);
        $this->assertSame($this->user->id, $transfer->user_id);
        $this->assertSame(6, $this->quantity($this->source));
        $this->assertSame(0, $this->quantity($this->destination));
        $this->assertEquals(10, Stock::sum('quantity') + InventoryTransfer::where('status', 'pending')->sum('quantity'));
        $this->patch(route('transfers.'.$action, $transfer))->assertRedirect(route('transfers.index'));
        $this->assertSame($status, $transfer->fresh()->status);
        foreach (['complete', 'cancel'] as $repeat) {
            $this->patchJson(route('transfers.'.$repeat, $transfer))->assertUnprocessable();
        }
        $this->assertSame($source, $this->quantity($this->source));
        $this->assertSame($destination, $this->quantity($this->destination));
        $this->assertEquals(10, Stock::sum('quantity'));
    }

    public function test_completion_adds_to_existing_destination(): void
    {
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->destination->warehouse_id, 'quantity' => 8]);
        $this->post(route('transfers.store'), $this->transfer())->assertRedirect();
        $this->patch(route('transfers.complete', InventoryTransfer::sole()))->assertRedirect();
        $this->assertSame(12, $this->quantity($this->destination));
        $this->assertSame(6, $this->quantity($this->source));
    }

    public function test_transfer_cannot_overdraw_or_use_missing_stock(): void
    {
        $this->postJson(route('transfers.store'), $this->transfer(['quantity' => 11]))->assertUnprocessable();
        $this->postJson(route('transfers.store'), $this->transfer(['from_warehouse_id' => $this->destination->warehouse_id, 'to_warehouse_id' => $this->source->warehouse_id]))->assertUnprocessable();
        $this->assertSame(10, $this->quantity($this->source));
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public static function roles(): array
    {
        return [['admin'], ['manager'], ['worker']];
    }

    #[DataProvider('roles')]
    public function test_supported_roles_can_use_both_workflows(string $role): void
    {
        $this->user->update(['role' => $role]);
        $this->post(route('movements.store'), $this->movement(['type' => 'in']))->assertRedirect(route('movements.index'));
        $this->post(route('transfers.store'), $this->transfer())->assertRedirect(route('transfers.index'));
        $this->patch(route('transfers.complete', InventoryTransfer::sole()))->assertRedirect(route('transfers.index'));
    }

    public function test_guest_and_unrecognized_role_cannot_mutate_stock(): void
    {
        $transfer = InventoryTransfer::create($this->transfer(['user_id' => $this->user->id, 'status' => 'pending']));
        foreach (['guest', 'unknown'] as $role) {
            if ($role === 'guest') {
                auth()->logout();
            } else {
                // In-memory role avoids depending on the database enum accepting invalid roles.
                $this->user->role = 'unknown';
                $this->actingAs($this->user);
            }
            $responses = [
                $this->postJson(route('movements.store'), $this->movement()),
                $this->postJson(route('transfers.store'), $this->transfer()),
                $this->patchJson(route('transfers.complete', $transfer)),
                $this->patchJson(route('transfers.cancel', $transfer)),
            ];
            foreach ($responses as $response) {
                $response->assertStatus($role === 'guest' ? 401 : 403);
            }
        }
        $this->assertSame(10, $this->quantity($this->source));
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 1);
    }

    public static function failedOperations(): array
    {
        return [['movement'], ['start'], ['complete'], ['cancel']];
    }

    #[DataProvider('failedOperations')]
    public function test_database_changes_roll_back_when_a_later_save_fails(string $operation): void
    {
        $service = app(\App\Services\InventoryService::class);
        $transfer = null;
        if (in_array($operation, ['complete', 'cancel'], true)) {
            $transfer = $service->startTransfer($this->transfer(['user_id' => $this->user->id]));
        }
        $beforeStocks = Stock::orderBy('id')->get()->toArray();
        $beforeTransfers = InventoryTransfer::orderBy('id')->get()->toArray();
        $fail = fn () => throw new \RuntimeException('Szándékos teszthiba');
        if ($operation === 'movement') {
            InventoryMovement::created($fail);
        } elseif ($operation === 'start') {
            InventoryTransfer::created($fail);
        } else {
            InventoryTransfer::updated($fail);
        }
        try {
            match ($operation) {
                'movement' => $service->recordMovement($this->movement(['user_id' => $this->user->id])),
                'start' => $service->startTransfer($this->transfer(['user_id' => $this->user->id])),
                'complete' => $service->finalizeTransfer($transfer, true),
                'cancel' => $service->finalizeTransfer($transfer, false),
            };
            $this->fail('A szándékos mentési hibának meg kellett volna szakítania a műveletet.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Szándékos teszthiba', $e->getMessage());
        }
        $this->assertSame($beforeStocks, Stock::orderBy('id')->get()->toArray());
        $this->assertSame($beforeTransfers, InventoryTransfer::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stale_transfer_instance_cannot_be_completed_twice(): void
    {
        $service = app(\App\Services\InventoryService::class);
        $transfer = $service->startTransfer($this->transfer(['user_id' => $this->user->id]));
        $stale = InventoryTransfer::findOrFail($transfer->id);
        $service->finalizeTransfer($transfer, true);
        try {
            $service->finalizeTransfer($stale, true);
            $this->fail('Az elavult objektum nem engedhet második jóváírást.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('transfer', $e->errors());
        }
        $this->assertSame(4, $this->quantity($this->destination));
        $this->assertSame(6, $this->quantity($this->source));
    }

    public function test_receipt_and_completion_cannot_overflow_stock_column(): void
    {
        Stock::where('warehouse_id', $this->source->warehouse_id)->update(['quantity' => 2147483647]);
        $this->postJson(route('movements.store'), $this->movement(['type' => 'in', 'quantity' => 1]))->assertUnprocessable();
        $this->assertSame(2147483647, $this->quantity($this->source));
        $this->assertDatabaseCount('inventory_movements', 0);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->destination->warehouse_id, 'quantity' => 2147483647]);
        $this->post(route('transfers.store'), $this->transfer())->assertRedirect();
        $transfer = InventoryTransfer::sole();
        $this->patchJson(route('transfers.complete', $transfer))->assertUnprocessable();
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertSame(2147483647, $this->quantity($this->destination));
    }

    public function test_exact_stock_can_be_transferred_and_cancelled(): void
    {
        $this->post(route('transfers.store'), $this->transfer(['quantity' => 10]))->assertRedirect();
        $this->assertSame(0, $this->quantity($this->source));
        $this->patch(route('transfers.cancel', InventoryTransfer::sole()))->assertRedirect();
        $this->assertSame(10, $this->quantity($this->source));
    }

    public function test_html_validation_error_is_visible_and_preserves_input(): void
    {
        $response = $this->from(route('movements.create'))->post(route('movements.store'), $this->movement(['quantity' => 11]));
        $response->assertRedirect(route('movements.create'))->assertSessionHasErrors('quantity')->assertSessionHasInput('quantity', 11);
        $this->get(route('movements.create'))->assertOk()->assertSee('Nincs elegendő készlet')->assertSee('value="11"', false);
    }
}
