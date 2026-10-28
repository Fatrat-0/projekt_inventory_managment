<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Shipment, ShipmentItem, Stock, User, Warehouse};
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;
    private Product $product;
    private Product $other;
    private Warehouse $origin;
    private Warehouse $a;
    private Warehouse $b;
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->actor);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'LOAD-1', 'price' => 10, 'category_id' => $category->category_id]);
        $this->other = Product::create(['product_name' => 'Kesztyű', 'sku' => 'LOAD-2', 'price' => 20, 'category_id' => $category->category_id]);
        $this->origin = Warehouse::create(['warehouse_name' => 'Indulás', 'location' => 'Budapest']);
        $this->a = Warehouse::create(['warehouse_name' => 'Cél A', 'location' => 'Győr']);
        $this->b = Warehouse::create(['warehouse_name' => 'Cél B', 'location' => 'Pécs']);
        foreach ([$this->product, $this->other] as $p) Stock::create(['product_id' => $p->product_id, 'warehouse_id' => $this->origin->warehouse_id, 'quantity' => 20]);
    }
    private function payload(): array
    {
        return ['reference' => 'Körjárat', 'vehicle_label' => 'ABC-123', 'origin_warehouse_id' => $this->origin->warehouse_id,
            'stops' => [
                ['warehouse_id' => $this->a->warehouse_id, 'items' => [['product_id' => $this->product->product_id, 'quantity' => 4], ['product_id' => $this->other->product_id, 'quantity' => 3]]],
                ['warehouse_id' => $this->b->warehouse_id, 'items' => [['product_id' => $this->product->product_id, 'quantity' => 5]]],
            ]];
    }
    private function draft(): Shipment
    {
        $this->post(route('shipments.store'), $this->payload())->assertRedirect();
        return Shipment::sole();
    }
    private function start(Shipment $s): void { $this->post(route('shipments.start', $s), ['version' => $s->version])->assertRedirect()->assertSessionHasNoErrors(); }
    private function qty(Warehouse $w, ?Product $p = null): int { return (int)Stock::where('warehouse_id', $w->warehouse_id)->where('product_id', ($p ?? $this->product)->product_id)->value('quantity'); }

    public function test_draft_and_edit_do_not_change_stock_and_plan_is_reverse_delivery_order(): void
    {
        $s = $this->draft();
        $this->assertSame('draft', $s->status);
        $this->assertSame(20, $this->qty($this->origin));
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->get(route('shipments.show', $s))->assertOk()->assertSeeInOrder(['1. felrakodás · Cél B', '2. felrakodás · Cél A']);
        $data = $this->payload(); $data['version'] = 1; $data['stops'] = array_reverse($data['stops']);
        $this->put(route('shipments.update', $s), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $s->fresh()->version);
        $this->assertEquals([$this->b->warehouse_id, $this->a->warehouse_id], $s->fresh()->stops->pluck('warehouse_id')->all());
        $this->get(route('shipments.show', $s))->assertOk()->assertSeeInOrder(['1. felrakodás · Cél A', '2. felrakodás · Cél B']);
        $this->assertSame(20, $this->qty($this->origin));
    }
    public function test_start_deducts_all_items_once_and_uses_authenticated_actor(): void
    {
        $s = $this->draft(); $this->start($s); $this->start($s);
        $this->assertSame(11, $this->qty($this->origin));
        $this->assertSame(17, $this->qty($this->origin, $this->other));
        $this->assertSame(0, $this->qty($this->a));
        $this->assertDatabaseCount('inventory_transfers', 3);
        $this->assertSame(3, InventoryTransfer::where('user_id', $this->actor->id)->where('status', 'pending')->count());
        $this->assertSame('in_transit', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->started_at);
    }
    public function test_insufficient_combined_quantity_rolls_back_entire_start_and_can_retry(): void
    {
        $s = $this->draft();
        Stock::where('product_id', $this->product->product_id)->update(['quantity' => 8]);
        $this->postJson(route('shipments.start', $s), ['version' => 1])->assertUnprocessable();
        $this->assertSame(8, $this->qty($this->origin));
        $this->assertSame(20, $this->qty($this->origin, $this->other));
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(0, ShipmentItem::whereNotNull('transfer_id')->count());
        $this->assertSame('draft', $s->fresh()->status);
        Stock::where('product_id', $this->product->product_id)->update(['quantity' => 20]);
        $this->start($s);
        $this->assertDatabaseCount('inventory_transfers', 3);
    }
    public function test_receipts_are_sequential_idempotent_and_complete_shipment(): void
    {
        $s = $this->draft(); $this->start($s); [$a, $b] = $s->stops()->get()->all();
        $this->patchJson(route('shipments.receive', [$s, $b]))->assertUnprocessable()->assertJsonValidationErrors('shipment');
        $this->assertSame(0, $this->qty($this->b));
        foreach ([$a, $a, $b, $b] as $stop) $this->patch(route('shipments.receive', [$s, $stop]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4, $this->qty($this->a)); $this->assertSame(3, $this->qty($this->a, $this->other));
        $this->assertSame(5, $this->qty($this->b)); $this->assertSame(11, $this->qty($this->origin));
        $this->assertSame('completed', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->finished_at);
        $this->assertSame($this->actor->id, $a->fresh()->received_by);
        $this->patchJson(route('shipments.cancel', $s))->assertUnprocessable();
    }
    public function test_receipt_overflow_rolls_back_every_item_in_the_stop(): void
    {
        $s = $this->draft(); $this->start($s); $stop = $s->stops()->first();
        Stock::create(['product_id' => $this->other->product_id, 'warehouse_id' => $this->a->warehouse_id, 'quantity' => 2147483647]);
        $this->patchJson(route('shipments.receive', [$s, $stop]))->assertUnprocessable();
        $this->assertSame(0, $this->qty($this->a));
        $this->assertNull($stop->fresh()->received_at);
        $this->assertSame(3, InventoryTransfer::where('status', 'pending')->count());
    }
    public function test_partial_cancellation_returns_only_unreceived_items_once(): void
    {
        $s = $this->draft(); $this->start($s); $a = $s->stops()->first();
        $this->patch(route('shipments.receive', [$s, $a]))->assertRedirect();
        foreach ([1,2] as $_) $this->patch(route('shipments.cancel', $s))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(16, $this->qty($this->origin));
        $this->assertSame(4, $this->qty($this->a)); $this->assertSame(0, $this->qty($this->b));
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertSame(2, InventoryTransfer::where('status', 'completed')->count());
        $this->assertSame(1, InventoryTransfer::where('status', 'cancelled')->count());
        $this->patchJson(route('shipments.receive', [$s, $s->stops()->get()->last()]))->assertUnprocessable();
    }
    public function test_draft_cancellation_never_touches_stock_and_cannot_restart(): void
    {
        $s = $this->draft(); $this->patch(route('shipments.cancel', $s))->assertRedirect();
        $this->assertSame(20, $this->qty($this->origin)); $this->assertDatabaseCount('inventory_transfers', 0);
        $this->postJson(route('shipments.start', $s), ['version' => 1])->assertUnprocessable();
    }
    public function test_cancellation_overflow_rolls_back_previously_returned_items(): void
    {
        $s = $this->draft(); $this->start($s);
        Stock::where('product_id', $this->other->product_id)->where('warehouse_id', $this->origin->warehouse_id)->update(['quantity' => 2147483647]);
        $this->patchJson(route('shipments.cancel', $s))->assertUnprocessable();
        $this->assertSame(11, $this->qty($this->origin));
        $this->assertSame(2147483647, $this->qty($this->origin, $this->other));
        $this->assertSame('in_transit', $s->fresh()->status);
        $this->assertSame(3, InventoryTransfer::where('status', 'pending')->count());
    }
    public function test_started_plan_cannot_be_edited_and_stale_draft_cannot_overwrite_or_start(): void
    {
        $s = $this->draft(); $data = $this->payload() + ['version' => 1];
        $this->put(route('shipments.update', $s), $data)->assertRedirect();
        $this->putJson(route('shipments.update', $s), $data)->assertUnprocessable();
        $this->postJson(route('shipments.start', $s), ['version' => 1])->assertUnprocessable();
        $this->start($s->fresh());
        $this->putJson(route('shipments.update', $s), array_replace($data, ['version' => 3]))->assertUnprocessable();
        $this->get(route('shipments.edit', $s))->assertStatus(409);
    }
    public function test_managed_transfers_cannot_be_completed_or_cancelled_individually(): void
    {
        $s = $this->draft(); $this->start($s); $transfer = InventoryTransfer::firstOrFail();
        foreach (['transfers.complete', 'transfers.cancel'] as $route) $this->patchJson(route($route, $transfer))->assertUnprocessable()->assertJsonValidationErrors('transfer');
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Szállítmány megnyitása');
    }
    public function test_stop_from_another_shipment_is_not_accepted(): void
    {
        $s = $this->draft(); $another = app(ShipmentService::class)->save($this->payload(), $this->actor->id);
        $this->start($s);
        $this->patchJson(route('shipments.receive', [$s, $another->stops()->first()]))->assertNotFound();
    }
    public function test_later_database_error_rolls_back_all_dispatch_changes(): void
    {
        $s = $this->draft(); $count = 0;
        InventoryTransfer::created(function () use (&$count) { if (++$count === 2) throw new \RuntimeException('dispatch failure'); });
        try { app(ShipmentService::class)->start($s, $this->actor->id, 1); $this->fail('Expected failure.'); }
        catch (\RuntimeException $e) { $this->assertSame('dispatch failure', $e->getMessage()); }
        finally { Event::forget('eloquent.created: '.InventoryTransfer::class); }
        $this->assertSame(20, $this->qty($this->origin)); $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(0, ShipmentItem::whereNotNull('transfer_id')->count());
        $this->assertSame('draft', $s->fresh()->status);
    }
    public static function invalidPlans(): array
    {
        return [['reference', ''], ['origin_warehouse_id', 99999], ['stops', []], ['stops.0.warehouse_id', 1],
            ['stops.1.warehouse_id', 2], ['stops.0.items.0.quantity', 0], ['stops.0.items.0.quantity', 1.5],
            ['stops.0.items.0.quantity', 2147483648], ['stops.0.items.0.product_id', 99999]];
    }
    #[DataProvider('invalidPlans')]
    public function test_invalid_plans_are_rejected_without_changes(string $path, mixed $value): void
    {
        $data = $this->payload(); data_set($data, $path, $value);
        $this->postJson(route('shipments.store'), $data)->assertUnprocessable()->assertJsonValidationErrors($path);
        $this->assertDatabaseCount('shipments', 0); $this->assertSame(20, $this->qty($this->origin));
    }
    public function test_duplicate_product_within_stop_is_rejected_but_across_stops_is_valid(): void
    {
        $data = $this->payload(); $data['stops'][0]['items'][] = $data['stops'][0]['items'][0];
        $this->postJson(route('shipments.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('stops.0.items.2.product_id');
        $this->post(route('shipments.store'), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
    }
    public function test_loading_plan_pdf_is_generated_and_template_preserves_reverse_order(): void
    {
        $s = $this->draft(); $s->load('origin', 'stops.warehouse', 'stops.items.product');
        $html = view('pdf.loading-plan', ['shipment' => $s])->render();
        $this->assertLessThan(strpos($html, '2. felrakodás · Cél A'), strpos($html, '1. felrakodás · Cél B'));
        $response = $this->get(route('shipments.pdf', $s))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));
        $stocks = Stock::orderBy('id')->get()->toArray();
        $preview = $this->get(route('shipments.pdf', ['shipment'=>$s,'preview'=>1]))->assertOk()->assertHeader('content-type','application/pdf');
        $this->assertStringStartsWith('inline;', $preview->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $preview->getContent());
        $this->assertSame($stocks, Stock::orderBy('id')->get()->toArray());
    }
    public function test_invalid_pdf_preview_parameter_is_rejected(): void
    {
        $s=$this->draft();
        foreach (['bad', ['bad']] as $preview) $this->getJson(route('shipments.pdf',['shipment'=>$s,'preview'=>$preview]))->assertUnprocessable()->assertJsonValidationErrors('preview');
    }
    public function test_guest_and_unsupported_role_cannot_access_shipment_actions(): void
    {
        $s = $this->draft(); $stop = $s->stops()->first(); auth()->logout();
        foreach ([null, 'invalid'] as $role) {
            if ($role) { $this->actor->role = $role; $this->actingAs($this->actor); }
            $status = $role ? 403 : 401;
            $this->getJson(route('shipments.show', $s))->assertStatus($status);
            $this->getJson(route('shipments.pdf', $s))->assertStatus($status);
            $this->getJson(route('shipments.pdf', ['shipment'=>$s,'preview'=>1]))->assertStatus($status);
            $this->postJson(route('shipments.start', $s), ['version' => 1])->assertStatus($status);
            $this->patchJson(route('shipments.receive', [$s, $stop]))->assertStatus($status);
            $this->patchJson(route('shipments.cancel', $s))->assertStatus($status);
            $this->postJson(route('shipments.store'), $this->payload())->assertStatus($status);
        }
    }
}
