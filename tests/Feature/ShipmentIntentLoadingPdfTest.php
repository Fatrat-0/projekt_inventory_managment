<?php

namespace Tests\Feature;

use App\Models\{Shipment, ShipmentIntentAssignment, User};
use App\Services\ShipmentPlanningService;
use App\Support\ShipmentLoadingPlanPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentIntentLoadingPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Shipment $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@raktar.hu')->sole();
        $this->plan = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
    }

    public function test_draft_pdf_lists_only_active_intents_in_reverse_loading_order(): void
    {
        $firstStop = $this->plan->stops()->firstOrFail();
        $omitted = $this->plan->intentAssignments()->active()->whereBelongsTo($firstStop, 'stop')->firstOrFail()->intent;
        app(ShipmentPlanningService::class)->detach($this->admin, $this->plan, $omitted, [
            'operation_key' => (string) Str::uuid(), 'version' => $this->plan->version,
        ]);
        $firstStop->items()->create(['product_id' => $omitted->product_id, 'quantity' => 777]);

        $loadingPlan = ShipmentLoadingPlanPdf::forShipment($this->plan->refresh());
        $this->assertSame([1, 2], array_column($loadingPlan['stops'], 'position'));
        $this->assertSame([2, 1], array_column($loadingPlan['loading_stops'], 'position'));
        $this->assertSame([1, 2], array_column($loadingPlan['loading_stops'], 'loading_position'));
        $this->assertSame([40, 24], collect($loadingPlan['stops'])->pluck('items')->flatten(1)->pluck('quantity')->all());

        $html = view('pdf.loading-plan', ['shipment' => $this->plan, 'loadingPlan' => $loadingPlan])->render();
        $this->assertStringContainsString('TERVEZET', $html);
        $this->assertStringNotContainsString($omitted->reference, $html);
        $this->assertStringNotContainsString('777 db', $html);

        $response = $this->actingAs($this->admin)->get(route('shipments.pdf', $this->plan))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseCount('shipment_dispatches', 0);
        $this->assertDatabaseCount('shipment_transit_items', 0);
    }

    public function test_plan_policy_applies_and_missing_document_data_cannot_download(): void
    {
        $operator = User::factory()->create(['role' => 'worker']);
        $operator->addWarehouseMembership($this->plan->origin_warehouse_id, ['dispatch_operator']);
        $url = route('shipments.pdf', $this->plan);
        $this->actingAs($operator)->get($url)->assertOk();

        $receiver = User::factory()->create(['role' => 'worker']);
        $receiver->addWarehouseMembership($this->plan->stops()->firstOrFail()->warehouse_id, ['dispatch_operator']);
        $this->actingAs($receiver)->get($url)->assertForbidden();

        app(ShipmentPlanningService::class)->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $this->plan->origin_warehouse_id,
            'reference' => 'Üres fuvarterv a rakodólista próbájához',
        ]);
        $empty = Shipment::where('reference', 'Üres fuvarterv a rakodólista próbájához')->sole();
        $emptyUrl = route('shipments.pdf', $empty);
        $this->actingAs($this->admin)->get($emptyUrl)->assertNotFound();
        $empty->update(['status' => 'in_transit']);
        $this->get($emptyUrl)->assertNotFound();
    }

    public function test_dispatched_pdf_keeps_the_recorded_load_and_labels_after_edits(): void
    {
        $originAddress = $this->plan->origin->location;
        $item = $this->plan->intentAssignments()->active()->orderBy('id')->firstOrFail();
        $destinationAddress = $item->stop->warehouse->location;
        $this->assertNotEmpty($originAddress);
        $this->assertNotEmpty($destinationAddress);

        $this->actingAs($this->admin)->postJson(route('shipment-plans.dispatch.store', $this->plan), [
            'operation_key' => (string) Str::uuid(), 'version' => $this->plan->version, 'confirmed' => true,
            'note' => 'Tényleges feladási megjegyzés',
        ])->assertOk();

        $snapshot = $this->plan->dispatch()->sole()->snapshot;
        $this->assertSame($originAddress, $snapshot['shipment']['origin_warehouse_location']);
        $this->assertSame($destinationAddress, $snapshot['stops'][0]['warehouse_location']);
        $item->intent->product->update(['product_name' => 'Utólag átnevezett termék']);
        $item->stop->warehouse->update(['warehouse_name' => 'Utólag átnevezett célraktár', 'location' => 'Új célraktári cím']);
        $this->plan->origin->update(['location' => 'Új forrásraktári cím']);
        $this->plan->vehicle->update(['name' => 'Utólag átnevezett jármű']);

        $loadingPlan = ShipmentLoadingPlanPdf::forShipment($this->plan->refresh());
        $this->assertSame([2, 1], array_column($loadingPlan['loading_stops'], 'position'));
        $this->assertSame([200, 40, 24], collect($loadingPlan['stops'])->pluck('items')->flatten(1)->pluck('quantity')->all());
        $this->assertSame($snapshot['stops'][0]['warehouse_name'], $loadingPlan['stops'][0]['name']);
        $this->assertSame($snapshot['intents'][0]['product_name'], $loadingPlan['stops'][0]['items'][0]['name']);
        $this->assertStringStartsWith($snapshot['vehicle']['name'], $loadingPlan['vehicle']);
        $this->assertSame('Tényleges feladási megjegyzés', $loadingPlan['note']);
        $html = view('pdf.loading-plan', ['shipment' => $this->plan, 'loadingPlan' => $loadingPlan])->render();
        $this->assertStringContainsString($originAddress, $html);
        $this->assertStringContainsString($destinationAddress, $html);
        $this->assertStringNotContainsString('Új forrásraktári cím', $html);
        $this->assertStringNotContainsString('Új célraktári cím', $html);

        $response = $this->get(route('shipments.pdf', $this->plan))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
