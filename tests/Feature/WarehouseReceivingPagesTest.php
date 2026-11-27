<?php

namespace Tests\Feature;

use App\Models\{Shipment, ShipmentReceiptItem, ShipmentStop, User, WarehouseLocation};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseReceivingPagesTest extends TestCase
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

    public function test_receiving_queue_defaults_to_work_and_filters_shipments_products_status_and_warehouse(): void
    {
        $queue = route('warehouse-receiving-pages.index');
        $this->get($queue)->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get($queue)->assertOk()->assertSee('Jelenleg nincs nyitott átvételi feladat');
        $this->dispatch();

        $this->get($queue)->assertOk()->assertViewIs('warehouse-receiving.index')
            ->assertSee('Raktári feladatok munkatípusai')->assertSee('Kitárolás')->assertSee('Betárolás')
            ->assertSee(route('warehouse-receiving-pages.show', [$this->shipment, $this->pecs]), false)->assertSee('2 tétel')->assertSee('1 tétel')
            ->assertViewHas('stops', fn ($stops) => $stops->total() === 2);
        $this->get(route('warehouse-receiving-pages.index', ['search' => $this->shipment->shipment_number, 'per_page' => 1]))
            ->assertOk()->assertViewHas('stops', fn ($stops) => $stops->total() === 2 && $stops->count() === 1);
        $this->get(route('warehouse-receiving-pages.index', ['search' => 'DEMO-FUVAR-CSAVAR']))
            ->assertOk()->assertViewHas('stops', fn ($stops) => $stops->total() === 1 && $stops->first()['stop']['id'] === $this->pecs->id);
        $this->get(route('warehouse-receiving-pages.index', ['warehouse_id' => $this->gyor->warehouse_id, 'status' => 'pending']))
            ->assertOk()->assertViewHas('stops', fn ($stops) => $stops->total() === 1 && $stops->first()['stop']['id'] === $this->gyor->id);
        $this->getJson(route('shipment-plans.receiving.index', ['status' => 'unknown']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->get(route('warehouse-receiving-pages.index', ['status' => 'unknown']))
            ->assertOk()->assertSee('Válassz érvényes állapotot.');

        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $this->actingAs($receiver)->get($queue)->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->modelKeys() === [$this->pecs->warehouse_id])
            ->assertViewHas('stops', fn ($stops) => $stops->total() === 1 && $stops->first()['stop']['id'] === $this->pecs->id)
            ->assertDontSee('href="'.route('shipments.index').'"', false);
        $this->get(route('warehouse-receiving-pages.index', ['warehouse_id' => $this->gyor->warehouse_id]))
            ->assertOk()->assertViewHas('stops', fn ($stops) => $stops->total() === 0);
        foreach (['requester', 'dispatch_operator'] as $role) {
            $this->actingAs($this->member($this->pecs->warehouse_id, $role))->get($queue)->assertForbidden();
        }
    }

    public function test_receiving_worksheet_is_scoped_and_shows_partial_receipt_and_completed_history(): void
    {
        $this->dispatch();
        $local = route('warehouse-receiving-pages.show', [$this->shipment, $this->pecs]);
        $remote = route('warehouse-receiving-pages.show', [$this->shipment, $this->gyor]);
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $this->actingAs($receiver)->get($local)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('snapshot', fn ($data) => $data['stop']['item_count'] === 2 &&
                array_column($data['items'], 'sku') === ['DEMO-FUVAR-CSAVAR', 'DEMO-FUVAR-GEP'])
            ->assertSee('Átvétel rögzítése')->assertSee('Átvétel megerősítése')->assertSee('Fuvar megnyitása');
        $this->get($remote)->assertForbidden();

        $receipt = $this->receive($this->pecs, ['DEMO-FUVAR-CSAVAR' => 195], 'A maradék 5 db ellenőrzés alatt.');
        $this->get($local)->assertOk()->assertSee('Részben átvéve')
            ->assertViewHas('snapshot', fn ($data) => $data['stop']['remaining_quantity'] === 45 && $data['stop']['can_receive']
                && $data['stop']['received_quantity'] === 195 && $data['stop']['item_count'] === 2
                && array_column($data['items'], 'dispatched_quantity', 'sku') === ['DEMO-FUVAR-CSAVAR' => 200, 'DEMO-FUVAR-GEP' => 40]
                && array_column($data['items'], 'received_quantity', 'sku') === ['DEMO-FUVAR-CSAVAR' => 195, 'DEMO-FUVAR-GEP' => 0]
                && array_column($data['items'], 'remaining_quantity', 'sku') === ['DEMO-FUVAR-CSAVAR' => 5, 'DEMO-FUVAR-GEP' => 40])
            ->assertViewHas('history', fn ($history) => $history->total() === 1
                && $history->first()['id'] === $receipt['receipt']['id']
                && $history->first()['actor_id'] === $receiver->id
                && $history->first()['items'][0]['remaining_quantity_after_receipt'] === 5
                && $history->first()['items'][0]['note'] === 'A maradék 5 db ellenőrzés alatt.');
        $this->get(route('warehouse-receiving-pages.index', ['status' => 'partial']))->assertOk()
            ->assertViewHas('stops', fn ($stops) => $stops->total() === 1 && $stops->first()['stop']['remaining_quantity'] === 45);
        $this->assertSame(24, (int) $this->gyor->transitItems()->sum('remaining_quantity'));

        $this->receive($this->pecs);
        $this->get(route('warehouse-receiving-pages.index'))->assertOk()
            ->assertViewHas('stops', fn ($stops) => $stops->total() === 0);
        $this->get(route('warehouse-receiving-pages.index', ['status' => 'received']))->assertOk()
            ->assertSee('Teljesen átvéve')->assertViewHas('stops', fn ($stops) => $stops->total() === 1);
        $this->get($local)->assertOk()->assertViewHas('history', fn ($history) => $history->total() === 2)
            ->assertViewHas('snapshot', fn ($data) => !$data['stop']['can_receive'] && $data['shipment']['status'] === 'in_transit'
                && $data['stop']['received_quantity'] === 240 && $data['stop']['remaining_quantity'] === 0
                && array_column($data['items'], 'remaining_quantity', 'sku') === ['DEMO-FUVAR-CSAVAR' => 0, 'DEMO-FUVAR-GEP' => 0]);
    }

    public function test_putaway_queue_stays_open_for_a_completed_shipment_and_retains_completed_item_history(): void
    {
        $this->dispatch();
        $receiver = $this->member($this->pecs->warehouse_id, 'receiving_operator');
        $this->actingAs($this->admin);
        $local = $this->receive($this->pecs);
        $remote = $this->receive($this->gyor);
        $this->assertSame('completed', $remote['shipment']['status']);
        $item = ShipmentReceiptItem::findOrFail($local['items'][0]['id']);
        $remoteItem = ShipmentReceiptItem::findOrFail($remote['items'][0]['id']);
        $bin = $this->bin($this->pecs, 'ATV-TAR');
        $this->actingAs($receiver)->get(route('warehouse-putaway-pages.index', ['receipt_id' => $local['receipt']['id']]))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 2 && $items->sum('pending_putaway_quantity') === 240)
            ->assertSee(route('warehouse-putaway-pages.show', $item));
        $this->get(route('warehouse-putaway-pages.index', ['receipt_id' => $remote['receipt']['id']]))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 0);
        $this->get(route('warehouse-putaway-pages.show', $remoteItem))->assertForbidden();
        $this->get(route('warehouse-putaway-pages.show', $item))
            ->assertOk()->assertViewHas('detail', fn ($detail) => $detail['item']['pending_putaway_quantity'] === 200);
        $this->postJson(route('shipment-receipt-items.putaway.store', $item), [
            'operation_key' => (string) Str::uuid(), 'version' => $item->putaway_version, 'confirmed' => true,
            'to_location_id' => $bin->id, 'quantity' => 200, 'cargo_weight_kg' => 15, 'pallet_slots' => 0, 'site_verified' => true,
        ])->assertCreated();
        $this->get(route('warehouse-putaway-pages.index', ['search' => 'DEMO-FUVAR-CSAVAR']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 0);
        $this->get(route('warehouse-putaway-pages.index', ['search' => 'DEMO-FUVAR-CSAVAR', 'status' => 'completed']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->first()['placed_quantity'] === 200);
        $this->get(route('warehouse-putaway-pages.show', $item))->assertOk()
            ->assertViewHas('history', fn ($history) => $history->total() === 1 && $history->first()['location_code'] === $bin->code)
            ->assertViewHas('detail', fn ($detail) => $detail['item']['status'] === 'completed');
    }

    public function test_receiving_shipment_and_putaway_floor_plan_only_expose_permitted_destinations(): void
    {
        $this->dispatch();
        $this->actingAs($this->admin);
        $local = $this->receive($this->pecs);
        $item = ShipmentReceiptItem::findOrFail($local['items'][0]['id']);
        $localBin = $this->bin($this->pecs, 'TERV-HELY');
        $unplaced = $this->bin($this->pecs, 'TERVEN-KIVUL', false);
        $foreign = $this->bin($this->gyor, 'TAVOLI');
        $show = route('shipments.show', $this->shipment);
        $receivingUrl = route('warehouse-receiving-pages.show', [$this->shipment, $this->pecs]);
        $map = fn (WarehouseLocation $bin) => route('warehouse-putaway-pages.plan', [$item, $bin]);

        $dispatcher = $this->member($this->shipment->origin_warehouse_id, 'dispatch_operator');
        $this->actingAs($dispatcher)->get($show)->assertOk()->assertViewIs('shipments.plan')
            ->assertViewHas('receivingStops', fn ($stops) => count($stops) === 2 && !in_array(true, array_column($stops, 'can_open_receiving'), true))
            ->assertDontSee($receivingUrl);
        $this->get($map($localBin))->assertForbidden();

        $this->actingAs($this->member($this->pecs->warehouse_id, 'receiving_operator'))->get($show)
            ->assertOk()->assertViewIs('shipments.receiving')->assertSee($receivingUrl)
            ->assertViewHas('receivingStops', fn ($stops) => count($stops) === 1 && $stops[0]['stop']['id'] === $this->pecs->id);
        $this->get($map($localBin))->assertOk()->assertViewIs('warehouses.floor-plan')
            ->assertViewHas('plan', fn ($data) => $data['canEdit'] === false && $data['focusBin'] === $localBin->id);
        $this->get($map($unplaced))->assertNotFound();
        $this->get($map($foreign))->assertNotFound();
    }

    private function dispatch(): void
    {
        $this->actingAs($this->admin)->postJson(route('shipment-plans.dispatch.store', $this->shipment), [
            'operation_key' => (string) Str::uuid(), 'version' => $this->shipment->version, 'confirmed' => true,
        ])->assertOk();
    }

    private function receive(ShipmentStop $stop, array $quantities = [], ?string $note = null): array
    {
        $lines = $stop->transitItems()->where('remaining_quantity', '>', 0)->with('product')->get()
            ->filter(fn ($line) => $quantities === [] || isset($quantities[$line->product->sku]))
            ->map(fn ($line) => ['transit_item_id' => $line->id,
                'quantity' => $quantities[$line->product->sku] ?? $line->remaining_quantity, 'note' => $note])->values()->all();

        return $this->postJson(route('shipment-plans.stops.receipts.store', [$this->shipment, $stop]), [
            'operation_key' => (string) Str::uuid(), 'version' => $stop->fresh()->receiving_version, 'confirmed' => true, 'items' => $lines,
        ])->assertCreated()->json();
    }

    private function member(int $warehouse, string $role): User
    {
        $actor = User::factory()->create(['role' => 'worker']);
        $actor->addWarehouseMembership($warehouse, [$role]);

        return $actor;
    }

    private function bin(ShipmentStop $stop, string $code, bool $positioned = true): WarehouseLocation
    {
        $bin = $stop->warehouse->storageLocations()->create([
            'name' => $code, 'code' => $code, 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 50000,
        ]);
        if ($positioned) $bin->forceFill(['floor_x' => 1, 'floor_y' => 1, 'floor_width' => 2, 'floor_height' => 2])->save();

        return $bin;
    }
}
