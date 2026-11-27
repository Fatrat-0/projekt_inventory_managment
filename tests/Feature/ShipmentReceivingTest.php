<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, Shipment, ShipmentReceipt, ShipmentStop, ShipmentTransitItem, Stock, User};
use App\Services\{InventoryService, ShipmentReceiving, SmartPicking, WarehouseStockAvailability};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;
    private ShipmentStop $pecs;
    private ShipmentStop $gyor;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->shipment = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        [$this->pecs, $this->gyor] = $this->shipment->stops()->get()->all();
        $this->admin = User::where('email', 'admin@raktar.hu')->sole();
    }

    public function test_receiving_reads_and_writes_are_scoped_to_the_destination(): void
    {
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $dispatcher = $this->member($this->shipment->origin_warehouse_id, 'dispatch_operator');
        $requester = $this->member($this->pecs->warehouse_id, 'requester');
        $show = $this->url($this->pecs, 'receiving.show');
        $store = $this->url($this->pecs);
        $list = route('shipment-plans.receiving.index');
        $pending = route('shipment-plans.receiving.pending-putaway');
        $draftRequest = ['operation_key' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'items' => [
            ['transit_item_id' => 1, 'quantity' => 1],
        ]];

        $this->getJson($list)->assertUnauthorized();
        $this->actingAs($receiver)->getJson($list)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($store, $draftRequest)->assertConflict();
        $this->assertDatabaseCount('shipment_receipts', 0);
        $this->dispatch();

        $this->actingAs($receiver)->getJson($list)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stop.id', $this->pecs->id)
            ->assertJsonPath('data.0.stop.version', 1);
        $this->getJson($show)->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('stop.receiving_status', 'pending')->assertJsonPath('stop.remaining_quantity', 240);
        $this->getJson($this->url($this->gyor, 'receiving.show'))->assertForbidden();
        $this->getJson($this->url($this->gyor, 'receipts.index'))->assertForbidden();
        $this->getJson($pending)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($pending.'?warehouse_id='.$this->gyor->warehouse_id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->url($this->gyor), $this->command($this->gyor, $this->lines($this->gyor->id)))->assertForbidden();

        foreach ([$dispatcher, $requester] as $forbidden) {
            $this->actingAs($forbidden)->getJson($list)->assertForbidden();
            $this->getJson($show)->assertForbidden();
            $this->postJson($store, $this->command($this->pecs, $this->lines($this->pecs->id)))->assertForbidden();
        }
        $elsewhere = Shipment::where('id', '!=', $this->shipment->id)->where('planning_mode', Shipment::MODE_TRANSFER_INTENT)->firstOrFail();
        $this->actingAs($this->admin)->getJson(route('shipment-plans.stops.receiving.show', [$elsewhere, $this->pecs]))->assertNotFound();
        $this->postJson(route('shipment-plans.stops.receipts.store', [$elsewhere, $this->pecs]), $draftRequest)->assertNotFound();
        $this->assertDatabaseCount('shipment_receipts', 0);
    }

    public function test_two_stop_example_books_exactly_240_and_24_and_keeps_the_putaway_holds_after_completion(): void
    {
        $snapshot = $this->dispatch();
        $transit = $this->transit();
        $screw = $transit->first();
        app(InventoryService::class)->recordMovement([
            'type' => 'in', 'quantity' => 50, 'product_id' => $screw->product_id,
            'warehouse_id' => $screw->to_warehouse_id, 'user_id' => $this->admin->id,
        ]);
        $before = $this->balances();
        $placements = DB::table('warehouse_placements')->orderBy('id')->get()->toArray();
        $movements = InventoryMovement::count();

        $local = $transit->where('shipment_stop_id', $this->pecs->id)->values();
        $remote = $transit->where('shipment_stop_id', $this->gyor->id)->sole();
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $supervisor = $this->member($this->gyor->warehouse_id, 'supervisor');
        $response = $this->actingAs($receiver)->postJson($this->url($this->pecs), $this->command($this->pecs, $this->lines($this->pecs->id)))
            ->assertCreated()->assertJsonCount(2, 'items')
            ->assertJsonPath('stop.receiving_status', 'received')->assertJsonPath('stop.received_quantity', 240)
            ->assertJsonPath('stop.remaining_quantity', 0)->assertJsonPath('stop.version', 2)
            ->assertJsonPath('stop.received_by', $receiver->id)->assertJsonPath('shipment.status', 'in_transit')
            ->assertJsonPath('shipment.version', $this->shipment->version + 1);
        $this->assertSame([0, 0, 24], $this->transit()->pluck('remaining_quantity')->all());
        $this->assertNull($this->gyor->refresh()->received_at);
        $this->assertNull($this->shipment->fresh()->finished_at);
        $this->assertSame($before[$remote->id], $this->balance($remote));
        $this->assertSame(24, $remote->fresh()->remaining_quantity);

        foreach ($local as $item) {
            $this->assertBooked($item, $before[$item->id], $item->dispatched_quantity);
        }
        $this->getJson($this->url($this->pecs, 'receiving.show').'?remaining_only=1')->assertOk()
            ->assertJsonCount(0, 'items')->assertJsonPath('stop.received_quantity', 240);
        $this->getJson($this->url($this->pecs, 'receipts.index'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.items')->assertJsonPath('data.0.id', $response->json('receipt.id'));
        $this->getJson(route('shipment-plans.receiving.index'))->assertOk()->assertJsonCount(0, 'data');

        $gyorRequest = $this->command($this->gyor, $this->lines($this->gyor->id));
        $second = $this->actingAs($supervisor)->postJson($this->url($this->gyor), $gyorRequest)
            ->assertCreated()->assertJsonCount(1, 'items')->assertJsonPath('stop.received_quantity', 24)
            ->assertJsonPath('shipment.status', 'completed')->assertJsonPath('shipment.version', $this->shipment->version + 2);
        $this->assertNotNull($this->shipment->refresh()->finished_at);
        $this->assertNotNull($this->gyor->refresh()->received_at);
        $this->assertSame([0, 0, 0], $this->transit()->pluck('remaining_quantity')->all());
        $this->assertBooked($remote, $before[$remote->id], 24);
        $this->postJson($this->url($this->gyor), $gyorRequest)->assertCreated()->assertExactJson($second->json());

        $this->actingAs($this->admin)->getJson(route('shipment-plans.receiving.pending-putaway'))->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(264, (int) DB::table('shipment_receipt_items')->sum('pending_putaway_quantity'));
        $this->assertDatabaseCount('shipment_receipts', 2);
        $this->assertDatabaseCount('shipment_receipt_items', 3);
        $this->assertDatabaseCount('inventory_movements', $movements + 3);
        $this->assertEquals($placements, DB::table('warehouse_placements')->orderBy('id')->get()->toArray());
        $this->getJson(route('shipment-plans.dispatch.show', $this->shipment))->assertOk()->assertJsonPath('transit_quantity', 0)
            ->assertJsonPath('dispatch.snapshot', $snapshot);
        $this->getJson(route('shipment-plans.readiness', $this->shipment))->assertOk()
            ->assertJsonPath('shipment_status', 'completed')->assertJsonPath('dispatch_status', 'dispatched');
        $available = app(WarehouseStockAvailability::class)->snapshot($screw->to_warehouse_id, $screw->product_id);
        $this->assertSame(250, $available['physical_quantity']);
        $this->assertSame(50, $available['direct_issue_quantity']);
    }

    public function test_partial_receipts_keep_the_missing_quantity_open_and_allow_other_stops_first(): void
    {
        $this->dispatch();
        $screw = $this->transit()->first();
        $part = $this->transit()->last();
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $differentReceiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $quantityBefore = $this->balance($screw);
        $request = $this->command($this->pecs, [[
            'transit_item_id' => $screw->id, 'quantity' => 195, 'note' => '5 db hiányzik az ellenőrzéskor.',
        ]]);
        $first = $this->actingAs($receiver)->postJson($this->url($this->pecs), $request)
            ->assertCreated()->assertJsonPath('stop.receiving_status', 'partial')
            ->assertJsonPath('items.0.received_quantity', 195)->assertJsonPath('items.0.remaining_quantity', 5)
            ->assertJsonPath('items.0.pending_putaway_quantity', 195);
        $this->assertNull($this->pecs->refresh()->received_at);
        $this->assertSame('in_transit', $screw->fresh()->status);
        $this->assertBooked($screw, $quantityBefore, 195);
        $this->postJson($this->url($this->pecs), $request)->assertCreated()->assertExactJson($first->json());
        $this->postJson($this->url($this->pecs), array_replace($request, ['note' => 'Eltérő ismétlés']))->assertConflict();
        $this->actingAs($differentReceiver)->postJson($this->url($this->pecs), $request)->assertConflict();

        $this->actingAs($this->admin)->postJson($this->url($this->gyor), $this->command($this->gyor, $this->lines($this->gyor->id)))
            ->assertCreated()->assertJsonPath('shipment.status', 'in_transit');
        $this->assertSame(0, $part->fresh()->remaining_quantity);
        $this->assertSame(5, $screw->fresh()->remaining_quantity);
        $this->actingAs($receiver)->getJson($this->url($this->pecs, 'receipts.index'))->assertOk()
            ->assertJsonPath('data.0.items.0.note', '5 db hiányzik az ellenőrzéskor.');

        $this->postJson($this->url($this->pecs), $this->command($this->pecs, $this->lines($this->pecs->id)))
            ->assertCreated()->assertJsonPath('shipment.status', 'completed');
        $this->assertSame(200, $screw->fresh()->received_quantity);
        $this->assertSame([195, 5], $screw->receiptItems()->orderBy('id')->pluck('received_quantity')->all());
        $this->assertBooked($screw, $quantityBefore, 200);
        $this->postJson($this->url($this->pecs), $request)->assertCreated()->assertExactJson($first->json());

        $bin = $this->pecs->warehouse->storageLocations()->create([
            'code' => '5B-RESZATVETEL', 'name' => 'Részátvételi tárhely', 'kind' => 'shelf', 'max_weight_grams' => 50000,
        ]);
        [$earlier, $later] = $screw->receiptItems()->orderBy('id')->get()->all();
        $before = InventoryMovement::count();
        $existingPlacement = null;
        foreach ([[80, 1, 115], [60, 2, 55], [55, 3, 0]] as [$quantity, $version, $remaining]) {
            $response = $this->postJson(route('shipment-receipt-items.putaway.store', $earlier), [
                'operation_key' => (string) Str::uuid(), 'version' => $version, 'confirmed' => true, 'site_verified' => true,
                'to_location_id' => $bin->id, 'quantity' => $quantity, 'cargo_weight_kg' => 5, 'pallet_slots' => 0,
            ])->assertCreated()->assertJsonPath('item.pending_putaway_quantity', $remaining);
            if ($existingPlacement === null) {
                $existingPlacement = $bin->placements()->findOrFail($response->json('placement.id'));
                $existingPlacement->update(['load_requires_verification' => true]);
            }
            $this->assertSame(5, $later->refresh()->pending_putaway_quantity);
            $this->assertSame(1, $later->putaway_version);
        }
        $this->assertTrue($existingPlacement->refresh()->load_requires_verification);
        $this->assertSame(5000, $existingPlacement->cargo_weight_grams);
        $this->getJson(route('shipment-receipt-items.putaway.history', $earlier))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(route('shipment-receipt-items.putaway.history', $later))->assertOk()->assertJsonCount(0, 'data');
        $options = app(SmartPicking::class)->options($screw->to_warehouse_id, $screw->product_id, 195);
        $this->assertSame(195, $options['available_quantity']);
        $this->assertSame(5, $options['pending_putaway_quantity']);
        $this->assertSame([['location_id' => $bin->id, 'quantity' => 195]], $options['suggestion']);
        $this->assertDatabaseCount('inventory_movements', $before);

    }

    public function test_invalid_requests_do_not_book_or_advance_versions(): void
    {
        $this->dispatch();
        $item = $this->transit()->first();
        $elsewhere = $this->transit()->last();
        $command = $this->command($this->pecs, [['transit_item_id' => $item->id, 'quantity' => 1]]);
        $url = $this->url($this->pecs);
        $balance = $this->balances();
        $movementCount = InventoryMovement::count();

        foreach ([0, -1, 1.5, true, $item->remaining_quantity + 1] as $quantity) {
            $this->postJson($url, array_replace($command, ['items' => [['transit_item_id' => $item->id, 'quantity' => $quantity]]]))
                ->assertUnprocessable();
        }
        $this->postJson($url, array_replace($command, ['items' => [$command['items'][0], ['transit_item_id' => $elsewhere->id, 'quantity' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson($url, array_replace($command, ['items' => array_merge($command['items'], $command['items'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.transit_item_id');
        $this->postJson($url, $command + ['to_warehouse_id' => $this->gyor->warehouse_id])->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->postJson($url, array_replace($command, ['confirmed' => false]))->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, array_replace($command, ['version' => 2]))->assertConflict();
        $this->patchJson(route('shipments.receive', [$this->shipment, $this->pecs]), [])->assertConflict();
        $this->assertSame($balance, $this->balances());
        $this->assertDatabaseCount('inventory_movements', $movementCount);
        $this->assertDatabaseCount('shipment_receipts', 0);
        $this->assertSame(1, $this->pecs->refresh()->receiving_version);
    }

    public function test_late_receipt_failure_rolls_back_stock_transit_audit_and_versions(): void
    {
        $this->dispatch();
        $before = $this->balances();
        $transit = $this->transit()->pluck('remaining_quantity')->all();
        $version = $this->shipment->version;
        $count = InventoryMovement::count();
        $lines = $this->lines($this->pecs->id);
        DB::unprepared(<<<'SQL'
            CREATE TEMP TRIGGER reject_receipt_seal BEFORE UPDATE ON shipment_receipts
            WHEN NEW.result IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'Késői naplóhiba'); END
            SQL);
        try {
            app(ShipmentReceiving::class)->receive($this->admin, $this->shipment, $this->pecs, $this->command($this->pecs, $lines));
            $this->fail('A késői naplóhibának vissza kell görgetnie az átvételt.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Késői naplóhiba', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_receipt_seal');
        }

        $this->assertSame($before, $this->balances());
        $this->assertSame($transit, $this->transit()->pluck('remaining_quantity')->all());
        $this->assertSame($version, $this->shipment->refresh()->version);
        $this->assertNull($this->pecs->refresh()->received_at);
        $this->assertSame(1, $this->pecs->receiving_version);
        $this->assertDatabaseCount('inventory_movements', $count);
        $this->assertDatabaseCount('shipment_receipts', 0);
        $this->assertDatabaseCount('shipment_receipt_items', 0);
    }

    public function test_receipt_records_and_receiving_actor_are_protected(): void
    {
        $this->dispatch();
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $this->actingAs($receiver)->postJson($this->url($this->pecs), $this->command($this->pecs, $this->lines($this->pecs->id)))->assertCreated();
        $receipt = ShipmentReceipt::sole();
        $line = $receipt->items()->firstOrFail();
        foreach ([
            fn () => DB::table('shipment_receipts')->where('id', $receipt->id)->update(['note' => 'Felülírás']),
            fn () => DB::table('shipment_receipts')->where('id', $receipt->id)->delete(),
            fn () => DB::table('shipment_receipt_items')->where('id', $line->id)->update(['received_quantity' => 1]),
            fn () => DB::table('shipment_receipt_items')->where('id', $line->id)->delete(),
        ] as $write) {
            try {
                $write();
                $this->fail('A lezárt átvétel történeti adatai megőrzendők.');
            } catch (QueryException) {
                $this->assertDatabaseHas('shipment_receipts', ['id' => $receipt->id, 'actor_id' => $receiver->id]);
            }
        }
        try {
            $receiver->delete();
            $this->fail('Átvételi bizonylathoz tartozó fiók nem törölhető.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('átvételi bizonylathoz', $exception->errors()['user'][0]);
        }
    }

    private function dispatch(): array
    {
        $snapshot = $this->actingAs($this->admin)->postJson(route('shipment-plans.dispatch.store', $this->shipment), [
            'operation_key' => (string) Str::uuid(), 'version' => $this->shipment->version, 'confirmed' => true,
        ])->assertOk()->assertJsonCount(3, 'transit_items')->json('dispatch.snapshot');
        $this->shipment->refresh();

        return $snapshot;
    }

    private function member(int $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse, [$role]);

        return $user;
    }

    private function transit(): Collection
    {
        return $this->shipment->transitItems()->get();
    }

    private function lines(int $stop): array
    {
        return $this->transit()->where('shipment_stop_id', $stop)->where('remaining_quantity', '>', 0)
            ->map(fn (ShipmentTransitItem $item) => ['transit_item_id' => $item->id, 'quantity' => $item->remaining_quantity])->values()->all();
    }

    private function command(ShipmentStop $stop, array $lines): array
    {
        return ['operation_key' => (string) Str::uuid(), 'version' => $stop->refresh()->receiving_version, 'confirmed' => true, 'items' => $lines];
    }

    private function url(ShipmentStop $stop, string $operation = 'receipts.store'): string
    {
        return route('shipment-plans.stops.'.$operation, [$this->shipment, $stop]);
    }

    private function balance(ShipmentTransitItem $item): array
    {
        $quantities = Stock::where('product_id', $item->product_id)
            ->whereIn('warehouse_id', [$item->from_warehouse_id, $item->to_warehouse_id])->pluck('quantity', 'warehouse_id');

        return [(int) ($quantities[$item->from_warehouse_id] ?? 0), (int) ($quantities[$item->to_warehouse_id] ?? 0)];
    }

    private function balances(): array
    {
        return $this->transit()->mapWithKeys(fn (ShipmentTransitItem $item) => [$item->id => $this->balance($item)])->all();
    }

    private function assertBooked(ShipmentTransitItem $item, array $before, int $quantity): void
    {
        $this->assertSame([$before[0], $before[1] + $quantity], $this->balance($item));
        $stock = app(WarehouseStockAvailability::class)->snapshot($item->to_warehouse_id, $item->product_id);
        $this->assertSame($quantity, $stock['pending_putaway_quantity']);
        $this->assertSame($quantity, (int) $item->receiptItems()->sum('received_quantity'));
        $bookings = $item->receiptItems()->with('movement')->orderBy('id')->get();
        $this->assertSame($quantity, $bookings->sum(fn ($line) => $line->movement->quantity));
        foreach ($bookings as $line) {
            $movement = $line->movement;
            $this->assertSame('in', $movement->type);
            $this->assertEquals($item->to_warehouse_id, $movement->warehouse_id);
            $this->assertSame($line->received_quantity, $movement->stock_after - $movement->stock_before);
            $this->assertNull($movement->shipment_transit_item_id);
            $this->assertEquals($item->transfer_intent_id, $line->transfer_intent_id);
            $this->assertEquals($item->shipment_dispatch_id, $line->transitItem->shipment_dispatch_id);
        }
    }
}
