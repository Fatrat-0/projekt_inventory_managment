<?php

namespace Tests\Feature;

use App\Models\{Shipment, ShipmentReceiptItem, ShipmentStop, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WarehouseTaskCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_scope_all_three_work_types_independently_of_list_filters(): void
    {
        $this->seed();
        $plan = $this->plan('Kapacitás rendben');
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $source = (int) $plan->origin_warehouse_id;
        $url = route('warehouse-task-counts');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->member($source, 'requester'))->getJson($url)->assertOk()->assertExactJson(['counts' => []]);
        $this->actingAs($this->member($source, 'dispatch_operator'))->getJson($url)->assertOk()
            ->assertExactJson(['counts' => ['picking' => 1]]);
        $this->actingAs($this->member($pecs->warehouse_id, 'receiving_operator'))->getJson($url)->assertOk()
            ->assertExactJson(['counts' => ['receiving' => 0, 'putaway' => 0]]);

        $this->actingAs($this->admin());
        $this->postJson(route('shipment-plans.dispatch.store', $plan), [
            'operation_key' => (string) Str::uuid(), 'version' => $plan->version, 'confirmed' => true,
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['counts' => ['picking' => 1, 'receiving' => 2, 'putaway' => 0]]);

        $receiptItemId = $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => 100])->json('items.0.id');
        $this->getJson($url)->assertOk()->assertExactJson(['counts' => ['picking' => 1, 'receiving' => 2, 'putaway' => 1]]);
        $this->actingAs($this->member($pecs->warehouse_id, 'receiving_operator'));
        $this->getJson($url.'?warehouse_id='.$gyor->warehouse_id.'&search=semmi')->assertOk()
            ->assertExactJson(['counts' => ['receiving' => 1, 'putaway' => 1]]);
        foreach ([
            route('warehouse-receiving-pages.index', ['search' => 'nincs ilyen fuvar']),
            route('warehouse-receiving-pages.show', [$plan, $pecs]),
            route('warehouse-putaway-pages.index', ['status' => 'completed']),
            route('warehouse-putaway-pages.show', $receiptItemId),
        ] as $page) {
            $this->get($page)->assertOk()->assertDontSee('data-task-count="picking"', false)
                ->assertSee('data-task-count="receiving"', false)->assertSee('data-task-count="putaway"', false)
                ->assertSeeText('1 nyitott feladat');
        }

        $this->actingAs($this->member($gyor->warehouse_id, 'supervisor'))->getJson($url)->assertOk()
            ->assertExactJson(['counts' => ['picking' => 0, 'receiving' => 1, 'putaway' => 0]]);
        $this->actingAs($this->admin());
        $this->receive($plan, $pecs, ['DEMO-FUVAR-CSAVAR' => 100, 'DEMO-FUVAR-GEP' => 40]);
        $this->getJson($url)->assertOk()->assertExactJson(['counts' => ['picking' => 1, 'receiving' => 1, 'putaway' => 3]]);

        $item = ShipmentReceiptItem::findOrFail($receiptItemId);
        foreach ([1 => ['FUV-A-01', 40, 3], 2 => ['FUV-A-02', 60, 2]] as $version => [$code, $quantity, $pending]) {
            $location = $item->warehouse->storageLocations()->where('code', $code)->sole();
            $this->postJson(route('shipment-receipt-items.putaway.store', $item), [
                'operation_key' => (string) Str::uuid(), 'version' => $version, 'confirmed' => true,
                'quantity' => $quantity, 'to_location_id' => $location->id, 'cargo_weight_kg' => 5.5, 'pallet_slots' => 0,
                'dimensions_cm' => ['width' => 30, 'depth' => 20, 'height' => 15],
            ])->assertCreated();
            $this->getJson($url)->assertOk()->assertExactJson(['counts' => ['picking' => 1, 'receiving' => 1, 'putaway' => $pending]]);
        }
    }

    public function test_the_seeded_partial_picking_task_leaves_the_counter_after_the_last_pick(): void
    {
        $this->seed();
        $admin = $this->admin();
        $plan = $this->plan('Kitárolás folyamatban');
        $intent = $plan->intentAssignments()->sole()->intent;
        $url = route('warehouse-task-counts');
        $this->actingAs($admin)->getJson($url)->assertJsonPath('counts.picking', 1);
        $this->postJson(route('transfer-intents.picking-task.picks', $intent), [
            'operation_key' => (string) Str::uuid(), 'version' => $intent->version, 'quantity' => 10,
            'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => 10]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A próbatétel a kiadási területen található.'],
        ])->assertOk()->assertJsonPath('task.status', 'picked');
        $this->getJson($url)->assertOk()->assertExactJson(['counts' => ['picking' => 0, 'receiving' => 0, 'putaway' => 0]]);
    }

    private function plan(string $scenario): Shipment
    {
        return Shipment::where('reference', '4/A bemutató – '.$scenario)->sole();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@raktar.hu')->sole();
    }

    private function member(int $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse, [$role]);

        return $user;
    }

    private function receive(Shipment $plan, ShipmentStop $stop, array $quantities): TestResponse
    {
        $items = $stop->transitItems()->with('product')->get()->map(fn ($item) => isset($quantities[$item->product->sku])
            ? ['transit_item_id' => $item->id, 'quantity' => $quantities[$item->product->sku]] : null)->filter()->values()->all();

        return $this->postJson(route('shipment-plans.stops.receipts.store', [$plan, $stop]), [
            'operation_key' => (string) Str::uuid(), 'version' => $stop->fresh()->receiving_version, 'confirmed' => true,
            'items' => $items,
        ])->assertCreated();
    }
}
