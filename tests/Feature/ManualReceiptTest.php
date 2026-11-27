<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, ManualReceiptBatch, Partner, Product, Stock, TransferIntent, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, SmartPicking, TransferIntentService, TransferStockReservationService, WarehouseCapacity, WarehouseStockAvailability, WarehouseStorageDesigner};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
        $this->product = Product::factory()->create(['category_id' => Category::create(['category_name' => 'Kötőelemek'])->getKey()]);
        $this->warehouse = Warehouse::factory()->create();
    }

    public function test_zero_stock_requires_confirmation_and_receipt_replays_without_duplicate_booking(): void
    {
        $payload = $this->receipt(20);
        $this->postJson(route('movements.store'), array_diff_key($payload, ['confirmed' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('stocks', 0);
        $created = $this->postJson(route('movements.store'), $payload)->assertCreated()
            ->assertJsonPath('movement.stock_before', 0)->assertJsonPath('movement.stock_after', 20)
            ->assertJsonPath('batch.pending_putaway_quantity', 20)->assertJsonPath('batch.version', 1)->assertJsonPath('batch.status', 'pending');
        $this->postJson(route('movements.store'), $payload)->assertCreated()->assertExactJson($created->json());
        $this->postJson(route('movements.store'), array_replace($payload, ['quantity' => 21]))
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('manual_receipt_batches', 1);
        $this->assertDatabaseCount('shipment_receipt_items', 0);
        $this->assertBalance(20, 20, 0, 0);
    }

    public function test_legacy_html_receipt_is_protected_and_partial_putaways_keep_stock_and_history(): void
    {
        $supplier = Partner::create(['type' => 'supplier', 'name' => 'Beszállító']);
        $legacy = app(InventoryService::class)->recordMovement($this->identity() + ['user_id' => $this->admin->id, 'type' => 'in', 'quantity' => 50]);
        $this->assertNull($legacy->manualReceiptBatch);
        $this->post(route('movements.store'), array_diff_key($this->receipt(200), ['confirmed' => true]) + ['partner_id' => $supplier->id])
            ->assertRedirect(route('movements.index'));
        $batch = ManualReceiptBatch::sole();
        $anotherId = $this->postJson(route('movements.store'), $this->receipt(15))->assertCreated()->json('batch.id');
        $this->assertBalance(265, 215, 0, 50);
        $first = $this->bin('A-01');
        $second = $this->bin('A-02');
        $receiver = $this->member($this->warehouse, 'receiving_operator');
        $this->actingAs($receiver)->getJson($this->url('show', $batch))->assertOk()
            ->assertJsonPath('batch.partner_name', $supplier->name)->assertJsonPath('batch.received_quantity', 200);
        $command = $this->putaway($first, 120);
        $placed = $this->postJson($this->url('putaway', $batch), $command)->assertCreated()
            ->assertJsonPath('batch.status', 'partial')->assertJsonPath('batch.pending_putaway_quantity', 80)
            ->assertJsonPath('event.location_code', 'A-01')->assertJsonPath('event.actor_id', $receiver->id);
        $this->assertBalance(265, 95, 120, 170);
        $this->postJson($this->url('putaway', $batch), $command)->assertCreated()->assertExactJson($placed->json());
        $this->postJson($this->url('putaway', $batch), array_replace($command, ['quantity' => 119]))->assertConflict();
        $this->postJson($this->url('putaway', $batch), $this->putaway($second, 80))->assertConflict();
        $this->postJson($this->url('putaway', $batch), $this->putaway($second, 81, 2))->assertConflict();
        $complete = $this->postJson($this->url('putaway', $batch), $this->putaway($second, 80, 2))->assertCreated()
            ->assertJsonPath('batch.status', 'completed')->assertJsonPath('batch.pending_putaway_quantity', 0)
            ->assertJsonPath('batch.can_putaway', false)->assertJsonPath('batch.version', 3);
        $this->assertNotNull($complete->json('batch.closed_at'));
        $this->assertBalance(265, 15, 200, 250);
        $this->assertSame(120, $first->placements()->sole()->quantity);
        $this->assertSame(80, $second->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->getJson(route('movements.in.receipts.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $anotherId);

        $productName = $this->product->product_name;
        $warehouseName = $this->warehouse->warehouse_name;
        $this->product->update(['product_name' => 'Új terméknév']);
        $this->warehouse->update(['warehouse_name' => 'Új raktárnév']);
        $first->update(['code' => 'A-03']);
        $this->getJson($this->url('history', $batch))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.product_name', $productName)->assertJsonPath('data.1.warehouse_name', $warehouseName)
            ->assertJsonPath('data.1.location_code', 'A-01')->assertJsonPath('data.1.movement_id', $batch->movement_id);
    }

    public function test_manual_pending_stock_is_excluded_from_other_issuance_reservation_and_placement_paths(): void
    {
        Stock::create($this->identity() + ['quantity' => 5]);
        $this->postJson(route('movements.store'), $this->receipt(20))->assertCreated();
        $this->assertBalance(25, 20, 0, 5);
        $bin = $this->bin('A-01');
        $out = $this->identity() + ['type' => 'out', 'request_key' => (string) Str::uuid(), 'quantity' => 6];
        $this->postJson(route('movements.store'), $out + ['source_allocations' => [['source_type' => 'unassigned', 'quantity' => 6]]])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('movements.store'), $out)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertRejected(fn () => app(WarehouseCapacity::class)->place($bin, $this->identity() + [
            'quantity' => 6, 'cargo_weight_kg' => '1', 'user_id' => $this->admin->id,
        ]));
        $destination = Warehouse::factory()->create();
        $transfer = [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->warehouse->getKey(),
            'to_warehouse_id' => $destination->getKey(), 'quantity' => 6,
        ];
        $this->assertRejected(fn () => app(InventoryService::class)->startTransfer($transfer + ['user_id' => $this->admin->id]));
        $intents = app(TransferIntentService::class);
        $intent = TransferIntent::findOrFail($intents->create($this->admin, $transfer + ['operation_key' => (string) Str::uuid()])['intent']['id']);
        $intents->approve($this->admin, $intent, ['version' => 1, 'operation_key' => (string) Str::uuid()]);
        $this->assertRejected(fn () => app(TransferStockReservationService::class)->reserve($this->admin, $intent,
            ['version' => 2, 'operation_key' => (string) Str::uuid()]));
        $options = app(SmartPicking::class)->options($this->warehouse->getKey(), $this->product->getKey(), 5);
        $this->assertSame(5, $options['available_quantity']);
        $this->assertSame(5, $options['suggestion'][0]['quantity']);
        $this->postJson(route('movements.store'), array_replace($out, ['quantity' => 5]) + [
            'source_allocations' => [['source_type' => 'unassigned', 'quantity' => 5]],
        ])->assertOk();
        $this->assertBalance(20, 20, 0, 0);
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->assertDatabaseCount('warehouse_placements', 0);
    }

    public function test_recommendations_and_preview_share_capacity_geometry_and_confirmed_box_weight_rules(): void
    {
        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 5, 'gross_weight_kg' => '2.5']);
        $this->postJson(route('movements.store'), $this->receipt(25))->assertCreated();
        $batch = ManualReceiptBatch::sole();
        $known = $this->bin('B-01');
        $unknown = $this->bin('B-02', ['width_cm' => null]);
        $tooLight = $this->bin('B-03', ['max_weight_grams' => 4000]);
        $this->bin('B-04', ['active' => false]);
        $rack = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
            'version' => $this->warehouse->fresh()->floor_version, 'kind' => 'rack', 'code' => 'MANUAL-R',
            'name' => 'Próbaállvány', 'zone' => 'A', 'width' => 2, 'height' => 2, 'rack_height' => 2, 'x' => 0, 'y' => 0,
            'levels' => 1, 'bins' => 1, 'bin_weight' => 50, 'level_weight' => 50, 'system_weight' => 50,
        ]);
        $rackBin = $this->warehouse->storageSystems()->findOrFail($rack['id'])->locations()->sole();
        $rackBin->allowedCategories()->sync([Category::create(['category_name' => 'Másik kategória'])->getKey()]);

        $revision = $this->getJson($this->url('show', $batch))->assertOk()->json('packaging.revision');
        $cargo = ['quantity' => 10, 'pallet_slots' => 0, 'weight_source' => 'calculated',
            'cargo_weight_packaging' => $revision, 'packaging_confirmed' => true,
            'dimensions_cm' => ['width' => 25, 'height' => 25, 'depth' => 25]];
        $advice = $this->postJson($this->url('recommendations', $batch), $cargo)->assertOk()
            ->assertJsonPath('cargo.cargo_weight_grams', 5000)->assertJsonPath('recommendations.0.status', 'verified')
            ->assertJsonPath('recommendations.0.location_id', $known->id)->assertJsonPath('recommendations.1.status', 'conditional')
            ->assertJsonPath('recommendations.0.product_stock.bin', ['before' => 0, 'after' => 10]);
        $this->assertContains($rackBin->id, array_column($advice->json('rejected'), 'location_id'));
        $this->postJson($this->url('locations', $batch), $cargo + ['search' => 'B-03'])->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.location_id', $tooLight->id)
            ->assertJsonPath('locations.0.status', 'rejected');
        $this->postJson($this->url('preview', $batch), $cargo + ['version' => 1, 'to_location_id' => $unknown->id])->assertOk()
            ->assertJsonPath('site_verification_required', true)->assertJsonPath('can_putaway', false)
            ->assertJsonStructure(['blocking_reasons' => ['location_dimensions']]);
        $this->postJson($this->url('preview', $batch), $cargo + ['version' => 1, 'to_location_id' => $unknown->id, 'site_verified' => true])
            ->assertOk()->assertJsonPath('can_putaway', true);
        $this->postJson($this->url('preview', $batch), ['quantity' => 2, 'version' => 1, 'to_location_id' => $known->id, 'site_verified' => true])
            ->assertOk()->assertJsonPath('can_putaway', false)->assertJsonStructure(['blocking_reasons' => ['weight_input', 'pallet_kind']]);
        $tooBig = array_replace($cargo, ['dimensions_cm' => ['width' => 101]]);
        $this->postJson($this->url('putaway', $batch), $tooBig + $this->putaway($known, 10) + ['site_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('dimensions_cm');
        $this->postJson($this->url('putaway', $batch), $cargo + $this->putaway($rackBin, 10))
            ->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $command = $cargo + array_diff_key($this->putaway($unknown, 10), ['cargo_weight_kg' => true]);
        $this->postJson($this->url('putaway', $batch), $command)->assertUnprocessable()->assertJsonValidationErrors('site_verified');
        $this->postJson($this->url('putaway', $batch), $command + ['site_verified' => true])->assertCreated()
            ->assertJsonPath('event.cargo_weight_grams', 5000)->assertJsonPath('event.geometry_status', 'site_verified');
        $this->postJson($this->url('locations', $batch), $cargo + ['to_location_id' => $unknown->id])
            ->assertOk()->assertJsonPath('locations.0.product_stock.bin', ['before' => 10, 'after' => 20]);
        $this->assertBalance(25, 15, 10, 10);
    }

    public function test_authorization_and_late_audit_failure_leave_batch_and_stock_intact(): void
    {
        $this->postJson(route('movements.store'), $this->receipt(20))->assertCreated();
        $batch = ManualReceiptBatch::sole();
        $bin = $this->bin('C-01');
        $foreignWarehouse = Warehouse::factory()->create();
        $wrong = $this->member($foreignWarehouse, 'supervisor');
        $command = $this->putaway($bin, 20);
        $this->actingAs($wrong)->getJson($this->url('show', $batch))->assertForbidden();
        $this->getJson($this->url('history', $batch))->assertForbidden();
        $this->postJson($this->url('recommendations', $batch), ['quantity' => 1])->assertForbidden();
        $this->postJson($this->url('putaway', $batch), $command)->assertForbidden();
        $this->getJson(route('movements.in.receipts.index'))->assertOk()->assertJsonCount(0, 'data');
        $dispatcher = $this->member($this->warehouse, 'dispatch_operator');
        $this->actingAs($dispatcher)->getJson(route('movements.in.receipts.index'))->assertForbidden();

        $this->actingAs($this->member($this->warehouse, 'supervisor'));
        $foreignBin = $foreignWarehouse->storageLocations()->create(['code' => 'C-02', 'name' => 'Másik raktár',
            'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 50000]);
        $this->postJson($this->url('putaway', $batch), $this->putaway($foreignBin, 20))->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->postJson($this->url('putaway', $batch), $command + ['product_id' => $this->product->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');

        DB::unprepared("CREATE TRIGGER test_manual_putaway_audit BEFORE INSERT ON manual_receipt_putaways
            BEGIN SELECT RAISE(ABORT, 'Forced manual putaway audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url('putaway', $batch), $command);
            $this->fail('Expected late audit failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Forced manual putaway audit failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER test_manual_putaway_audit');
        }
        $this->assertBalance(20, 20, 0, 0);
        $this->assertSame(1, $batch->fresh()->putaway_version);
        $this->assertDatabaseCount('manual_receipt_putaways', 0);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->postJson($this->url('putaway', $batch), $command)->assertCreated();
        $this->assertBalance(20, 0, 20, 20);
    }

    public function test_receipt_batch_failure_rolls_back_the_inventory_movement_and_request_key(): void
    {
        $payload = $this->receipt(20);
        DB::unprepared("CREATE TRIGGER test_manual_receipt_batch BEFORE INSERT ON manual_receipt_batches
            BEGIN SELECT RAISE(ABORT, 'Forced manual receipt batch failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('movements.store'), $payload);
            $this->fail('Expected receipt batch failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Forced manual receipt batch failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER test_manual_receipt_batch');
        }
        foreach (['stocks', 'inventory_movements', 'inventory_movement_requests', 'manual_receipt_batches'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->postJson(route('movements.store'), $payload)->assertCreated();
        $this->assertBalance(20, 20, 0, 0);
    }

    public function test_putaway_rechecks_capacity_consumed_by_another_receipt_after_preview(): void
    {
        $first = $this->postJson(route('movements.store'), $this->receipt(5))->assertCreated()->json('batch.id');
        $second = $this->postJson(route('movements.store'), $this->receipt(5))->assertCreated()->json('batch.id');
        $bin = $this->bin('RACE-BIN', ['kind' => 'pallet', 'max_pallet_slots' => 1]);
        $data = array_replace($this->putaway($bin, 5), ['pallet_slots' => 1, 'site_verified' => true]);
        $this->postJson(route('movements.in.receipts.preview', $first), $data)->assertOk()->assertJsonPath('can_putaway', true);
        $this->postJson(route('movements.in.receipts.putaway', $second), array_replace($data, ['operation_key' => (string) Str::uuid()]))
            ->assertCreated();
        $this->postJson(route('movements.in.receipts.putaway', $first), $data)->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->assertBalance(10, 5, 5, 5);
        $this->assertDatabaseCount('manual_receipt_putaways', 1);
    }

    public function test_actor_cannot_remove_the_user_linked_to_the_putaway_audit(): void
    {
        $batch = $this->postJson(route('movements.store'), $this->receipt(5))->assertCreated()->json('batch.id');
        $actor = $this->member($this->warehouse, 'receiving_operator');
        $this->actingAs($actor)->postJson(route('movements.in.receipts.putaway', $batch), $this->putaway($this->bin('A-01'), 5))
            ->assertCreated();
        $this->delete('/profile', ['password' => 'password'])->assertMethodNotAllowed();
        $this->actingAs($this->admin)->patch(route('users.access', $actor), ['active' => false, 'lock_version' => 1])->assertRedirect();
        $this->assertFalse($actor->fresh()->isActive());
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
        $this->assertDatabaseHas('manual_receipt_putaways', ['actor_id' => $actor->id, 'actor_name' => $actor->name]);
    }

    private function receipt(int $quantity): array
    {
        return $this->identity() + ['type' => 'in', 'quantity' => $quantity, 'confirmed' => true,
            'reference' => 'BIZ-01', 'request_key' => (string) Str::uuid()];
    }

    private function identity(): array
    {
        return ['product_id' => $this->product->getKey(), 'warehouse_id' => $this->warehouse->getKey()];
    }

    private function bin(string $code, array $overrides = []): WarehouseLocation
    {
        $location = $this->warehouse->storageLocations()->make();
        $location->forceFill($overrides + ['code' => $code, 'name' => $code, 'kind' => 'shelf',
            'active' => true, 'max_weight_grams' => 50000, 'width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        return $location;
    }

    private function putaway(WarehouseLocation $bin, int $quantity, int $version = 1): array
    {
        return ['quantity' => $quantity, 'version' => $version, 'to_location_id' => $bin->id, 'confirmed' => true,
            'operation_key' => (string) Str::uuid(), 'cargo_weight_kg' => 5, 'pallet_slots' => 0,
            'dimensions_cm' => ['width' => 25, 'height' => 25, 'depth' => 25]];
    }

    private function url(string $action, ManualReceiptBatch $batch): string
    {
        return route('movements.in.receipts.'.$action, $batch);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function assertBalance(int $physical, int $pending, int $placed, int $pickable): void
    {
        $balance = app(WarehouseStockAvailability::class)->snapshot($this->warehouse->getKey(), $this->product->getKey());
        $this->assertTrue($balance['consistent']);
        $this->assertSame([$physical, $pending, $placed, $pickable], [$balance['physical_quantity'],
            $balance['pending_putaway_quantity'], $balance['assigned_quantity'], $balance['pickable_quantity']]);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected protected stock to be unavailable.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('quantity', $error->errors());
        }
    }
}
