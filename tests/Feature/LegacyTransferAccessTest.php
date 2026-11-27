<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Shipment, Stock, TransferIntent, User, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyTransferAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_standalone_commands_check_the_correct_warehouse_and_keep_history_visible_to_both_sides(): void
    {
        [$source, $target, $other] = Warehouse::factory()->count(3)->create()->all();
        Category::create(['category_name' => 'Átmozgatási jogosultságok']);
        $product = Product::factory()->create();
        $stock = Stock::create(['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 10]);
        $command = [
            'request_key' => (string) Str::uuid(), 'product_id' => $product->getKey(),
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(), 'quantity' => 2,
        ];
        $supervisor = $this->member($source, 'supervisor', 'manager');
        $receiver = $this->member($target, 'receiving_operator');
        $dispatcher = $this->member($source, 'dispatch_operator');
        $outsider = $this->member($other, 'supervisor', 'manager');
        $requester = $this->member($target, 'requester');
        foreach ([$receiver, $dispatcher, $outsider, $requester] as $actor) {
            $this->actingAs($actor)->postJson(route('transfers.store'), $command)->assertForbidden();
        }
        $this->assertSame(10, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->getJson(route('transfers.putaway-recommendations', $command))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('transfers.picking-sources', $command))->assertForbidden();
        $this->actingAs($dispatcher)->getJson(route('transfers.picking-sources', $command))->assertOk()
            ->assertJsonPath('available_quantity', 10);

        $this->actingAs($supervisor)->get(route('transfers.create'))->assertOk()
            ->assertViewHas('sourceWarehouses', fn ($warehouses) => $warehouses->modelKeys() === [$source->getKey()]);
        $this->post(route('transfers.store'), $command)->assertRedirectToRoute('transfers.index');
        $transfer = InventoryTransfer::sole();
        $this->assertSame(8, $stock->fresh()->quantity);
        $this->patchJson(route('transfers.complete', $transfer))->assertForbidden();

        $urls = [route('transfers.show', $transfer), route('export.transfer', $transfer)];
        foreach ([$dispatcher, $receiver] as $actor) {
            foreach ($urls as $url) $this->actingAs($actor)->get($url)->assertOk();
            $this->patchJson(route('transfers.cancel', $transfer))->assertForbidden();
        }
        foreach ([$requester, $outsider] as $actor) {
            foreach ($urls as $url) $this->actingAs($actor)->get($url)->assertForbidden();
            $this->patchJson(route('transfers.complete', $transfer))->assertForbidden();
            $this->get('/transfers')->assertOk()->assertDontSee($transfer->transfer_number);
            $this->get('/dashboard')->assertOk()->assertDontSee($transfer->transfer_number);
        }
        $this->actingAs($receiver)->patch(route('transfers.complete', $transfer))->assertRedirectToRoute('transfers.index');
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame(2, Stock::where('warehouse_id', $target->getKey())->sole()->quantity);

        $this->actingAs($supervisor)->post(route('transfers.store'), array_replace($command, ['request_key' => (string) Str::uuid()]))->assertRedirect();
        $pending = InventoryTransfer::where('status', 'pending')->sole();
        $this->patch(route('transfers.cancel', $pending))->assertRedirectToRoute('transfers.index');
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(8, $stock->fresh()->quantity);

        $this->actingAs($requester)->postJson(route('transfer-intents.store'), $command + ['operation_key' => (string) Str::uuid()])->assertCreated();
        $intent = TransferIntent::sole();
        $this->patchJson(route('transfer-intents.approve', $intent), ['version' => 1, 'operation_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($supervisor)->patchJson(route('transfer-intents.approve', $intent),
            ['version' => 1, 'operation_key' => (string) Str::uuid()])->assertOk();
    }

    public function test_old_shipment_commands_authorize_the_persisted_origin_and_actual_stop(): void
    {
        [$source, $target, $later, $remote] = Warehouse::factory()->count(4)->create()->all();
        Category::create(['category_name' => 'Fuvarjogosultságok']);
        $product = Product::factory()->create();
        $stock = Stock::create(['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 10]);
        $originSupervisor = $this->member($source, 'supervisor');
        $destinationSupervisor = $this->member($target, 'supervisor', 'manager');
        $receiver = $this->member($target, 'receiving_operator');
        $dispatcher = $this->member($source, 'dispatch_operator');
        $draft = [
            'reference' => 'Korábbi fuvar', 'origin_warehouse_id' => $source->getKey(),
            'stops' => collect([$target, $later])->map(fn ($warehouse) => [
                'warehouse_id' => $warehouse->getKey(), 'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
            ])->all(),
        ];
        $this->actingAs($destinationSupervisor)->postJson(route('shipments.store'), $draft)->assertForbidden();
        $this->actingAs($originSupervisor)->post(route('shipments.store'), $draft)->assertRedirect();
        $shipment = Shipment::sole();
        [$stop, $laterStop] = $shipment->stops()->get()->all();
        $this->putJson(route('shipments.update', $shipment), [
            'version' => 1, 'origin_warehouse_id' => $remote->getKey(),
        ] + $draft)->assertForbidden();
        $this->assertSame($source->getKey(), (int) $shipment->fresh()->origin_warehouse_id);

        $this->actingAs($receiver)->get(route('shipments.show', $shipment))->assertForbidden();
        $this->actingAs($dispatcher)->postJson(route('shipments.start', $shipment), ['version' => 1])->assertForbidden();
        $this->get(route('shipments.pdf', $shipment))->assertOk();
        $this->actingAs($originSupervisor)->post(route('shipments.start', $shipment), ['version' => 1])->assertRedirect();
        $this->assertSame(6, $stock->fresh()->quantity);

        $this->patchJson(route('shipments.receive', [$shipment, $stop]))->assertForbidden();
        $this->actingAs($receiver)->get(route('shipments.show', $shipment))->assertOk()
            ->assertSee('data-legacy-stop-id="'.$stop->id.'"', false)
            ->assertDontSee('data-legacy-stop-id="'.$laterStop->id.'"', false)
            ->assertDontSee($later->warehouse_name);
        $this->get(route('shipments.pdf', $shipment))->assertForbidden();
        $this->patchJson(route('shipments.receive', [$shipment, $laterStop]))->assertForbidden();
        $this->patchJson(route('shipments.cancel', $shipment))->assertForbidden();
        $this->patch(route('shipments.receive', [$shipment, $stop]))->assertRedirectToRoute('shipments.show', $shipment);
        $this->assertSame($receiver->id, (int) $stop->fresh()->received_by);
        $this->actingAs($originSupervisor)->patch(route('shipments.cancel', $shipment))->assertRedirect();
        $this->assertSame(8, $stock->fresh()->quantity);
    }

    private function member(Warehouse $warehouse, string $role, string $globalRole = 'worker'): User
    {
        $user = User::factory()->create(['role' => $globalRole]);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);
        return $user;
    }
}
