<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Shipment, Stock, User, Warehouse};
use App\Services\ShipmentService;
use App\Support\{ListingDates, ShipmentListContext, TransferListContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticsListingTest extends TestCase
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
        $this->actor = User::factory()->create(['role' => 'worker']); $this->actingAs($this->actor);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'Csavar_100%', 'sku' => 'LOAD-A', 'price' => 10, 'category_id' => $category->category_id]);
        $this->other = Product::create(['product_name' => 'Kesztyű', 'sku' => 'LOAD-B', 'price' => 20, 'category_id' => $category->category_id]);
        $this->origin = Warehouse::create(['warehouse_name' => 'Indulás', 'location' => 'Budapest']);
        $this->a = Warehouse::create(['warehouse_name' => 'Cél A', 'location' => 'Győr']);
        $this->b = Warehouse::create(['warehouse_name' => 'Cél B', 'location' => 'Pécs']);
        foreach ([$this->product, $this->other] as $p) Stock::create(['product_id' => $p->product_id, 'warehouse_id' => $this->origin->warehouse_id, 'quantity' => 100]);
    }
    private function payload(string $reference = 'Körjárat'): array
    {
        return ['reference' => $reference, 'vehicle_label' => 'AUTO-123', 'origin_warehouse_id' => $this->origin->warehouse_id, 'stops' => [
            ['warehouse_id' => $this->a->warehouse_id, 'items' => [['product_id' => $this->product->product_id, 'quantity' => 2]]],
            ['warehouse_id' => $this->b->warehouse_id, 'items' => [['product_id' => $this->other->product_id, 'quantity' => 3]]],
        ]];
    }
    private function shipment(string $reference = 'Körjárat', string $date = '2026-04-10 12:00:00'): Shipment
    {
        $s = app(ShipmentService::class)->save($this->payload($reference), $this->actor->id);
        $s->forceFill(['created_at' => $date])->save(); return $s;
    }
    private function transfer(string $date = '2026-04-10 12:00:00', string $status = 'pending'): InventoryTransfer
    {
        $t = InventoryTransfer::create(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->origin->warehouse_id, 'to_warehouse_id' => $this->a->warehouse_id,
            'user_id' => $this->actor->id, 'quantity' => 2, 'status' => $status]);
        $t->forceFill(['created_at' => $date])->save(); return $t;
    }
    public function test_shipment_status_counts_ignore_only_status_and_cover_all_pages(): void
    {
        for ($i=0; $i<17; $i++) $this->shipment('Shared_100% '.$i)->update(['status' => $i < 14 ? 'draft' : ($i < 16 ? 'in_transit' : 'completed')]);
        $this->shipment('SharedX1000');
        $params = ['search' => 'Shared_100%', 'origin_warehouse_id' => $this->origin->warehouse_id, 'date_from'=>'2026-04-10','date_to'=>'2026-04-10', 'status'=>'in_transit','per_page'=>15,'page'=>2];
        $response = $this->get(route('shipments.index', $params))->assertOk();
        $this->assertEquals(['draft'=>14,'in_transit'=>2,'completed'=>1], $response->viewData('statusCounts'));
        $this->assertSame(2, $response->viewData('shipments')->total());
        $this->assertCount(0, $response->viewData('shipments'));
        $all = $this->get(route('shipments.index', array_replace($params, ['status'=>null])))->assertOk();
        $this->assertCount(2, $all->viewData('shipments'));
    }
    public function test_transfer_status_counts_and_dates_preserve_filtered_road_quantity(): void
    {
        for ($i=0; $i<12; $i++) $this->transfer();
        $this->transfer(status:'completed'); $this->transfer('2026-04-11 00:00:00','cancelled');
        $response = $this->get(route('transfers.index', ['search'=>'Csavar_100%', 'status'=>'completed','date_from'=>'2026-04-10','date_to'=>'2026-04-10','page'=>2]))->assertOk();
        $this->assertEquals(['pending'=>12,'completed'=>1], $response->viewData('statusCounts'));
        $this->assertSame(0, $response->viewData('pendingQuantity'));
        $pending = $this->get(route('transfers.index', ['status'=>'pending','per_page'=>10,'page'=>2]))->assertOk();
        $this->assertCount(2, $pending->viewData('transfers')); $this->assertSame(24, $pending->viewData('pendingQuantity'));
    }
    public function test_product_and_destination_filters_must_match_the_same_stop_without_duplicate_shipments(): void
    {
        $wanted = $this->shipment();
        $data = $this->payload('Fordított');
        $data['stops'][0]['items'][0]['product_id'] = $this->other->product_id;
        $data['stops'][1]['items'][0]['product_id'] = $this->product->product_id;
        app(ShipmentService::class)->save($data, $this->actor->id);
        $response = $this->get(route('shipments.index', ['product_id'=>$this->product->product_id,'destination_warehouse_id'=>$this->a->warehouse_id]))->assertOk();
        $this->assertSame([$wanted->id], $response->viewData('shipments')->pluck('id')->all());
        $this->assertSame(['draft'=>1], $response->viewData('statusCounts'));
    }
    public static function listingRoutes(): array { return [['shipments.index','SZALL'],['transfers.index','ATM']]; }
    #[DataProvider('listingRoutes')]
    public function test_number_search_is_exact_case_insensitive_and_overflow_safe(string $route, string $prefix): void
    {
        $wanted = $route === 'shipments.index' ? $this->shipment() : $this->transfer();
        for ($i=0; $i<9; $i++) $route === 'shipments.index' ? $this->shipment() : $this->transfer();
        $key = $route === 'shipments.index' ? 'shipments' : 'transfers';
        foreach ([$prefix.'-000001', strtolower($prefix).'-1'] as $term) {
            $response = $this->get(route($route, ['search'=>$term]))->assertOk();
            $this->assertSame([$wanted->id], $response->viewData($key)->pluck('id')->all());
        }
        foreach ([$prefix.'-999999999999999999999999999999', $prefix.'-00000%', $prefix.'-1 nincsilyen'] as $term) $this->get(route($route, ['search'=>$term]))->assertOk()->assertViewHas($key, fn($rows) => $rows->total() === 0);
    }
    #[DataProvider('listingRoutes')]
    public function test_date_range_includes_full_last_day_and_quick_links_keep_filters_but_reset_page(string $route, string $prefix): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 00:30:00'));
        $make = fn($date) => $route === 'shipments.index' ? $this->shipment(date:$date) : $this->transfer($date);
        $make('2026-02-28 23:59:59'); $first=$make('2026-03-01 00:00:00'); $last=$make('2026-03-01 23:59:59'); $make('2026-03-02 00:00:00');
        $filters=['date_from'=>'2026-03-01','date_to'=>'2026-03-01','sort_by'=>'created_at','sort_dir'=>'asc'];
        $response=$this->get(route($route,$filters))->assertOk();
        $key=$route === 'shipments.index' ? 'shipments' : 'transfers';
        $this->assertSame([$first->id,$last->id],$response->viewData($key)->pluck('id')->all());
        $presets=ListingDates::presets($route,$filters+['page'=>3,'search'=>'abc']);
        parse_str(parse_url($presets['last7']['url'],PHP_URL_QUERY),$params);
        $this->assertSame('2026-02-23',$params['date_from']); $this->assertSame('2026-03-01',$params['date_to']);
        $this->assertSame('abc',$params['search']); $this->assertSame('asc',$params['sort_dir']); $this->assertArrayNotHasKey('page',$params);
    }
    public static function invalidFilters(): array
    {
        $cases=[];
        foreach (['shipments.index','transfers.index'] as $route) foreach (['date_from'=>'2026-02-30','date_to'=>['bad'],'search'=>['bad'],'sort_by'=>'invalid','sort_dir'=>'invalid','per_page'=>1,'page'=>0,'status'=>'invalid'] as $key=>$value) $cases[]=[$route,$key,$value];
        return $cases;
    }
    #[DataProvider('invalidFilters')]
    public function test_invalid_list_filters_are_rejected(string $route,string $key,mixed $value): void
    {
        $this->getJson(route($route,[$key=>$value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->getJson(route($route,['date_from'=>'2026-04-11','date_to'=>'2026-04-10']))->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }
    public function test_shipment_context_survives_create_edit_start_receive_cancel_and_return(): void
    {
        $params=['search'=>'Körjárat','status'=>'draft','sort_by'=>'reference','sort_dir'=>'asc','per_page'=>25,'page'=>2,'date_from'=>'2026-01-01','date_to'=>'2026-12-31'];
        $list=$this->get(route('shipments.index',$params))->assertOk(); $context=$list->viewData('context');
        $form=$this->get(route('shipments.create',['context'=>$context]))->assertOk();
        parse_str(parse_url($form->viewData('returnUrl'),PHP_URL_QUERY),$back);
        foreach ($params as $key=>$value) $this->assertEquals($value,$back[$key]);
        $this->post(route('shipments.store'),$this->payload()+['context'=>$context])->assertRedirect(); $s=Shipment::sole();
        $show=route('shipments.show',['shipment'=>$s,'context'=>$context]);
        $this->get($show)->assertOk()->assertViewHas('returnUrl',ShipmentListContext::url($context));
        $this->get(route('shipments.edit',['shipment'=>$s,'context'=>$context]))->assertOk()->assertViewHas('returnUrl',$show);
        $this->put(route('shipments.update',$s),$this->payload()+['version'=>1,'context'=>$context])->assertRedirect($show);
        $this->post(route('shipments.start',$s),['version'=>2,'context'=>$context])->assertRedirect($show);
        $this->patch(route('shipments.receive',[$s,$s->stops()->first()]),['context'=>$context])->assertRedirect($show);
        $this->patch(route('shipments.cancel',$s),['context'=>$context])->assertRedirect($show);
        $this->assertSame('cancelled',$s->fresh()->status);
        $transferContext=TransferListContext::encode(['date_from'=>'2026-04-10','date_to'=>'2026-04-10']);
        $this->assertStringContainsString('date_from=2026-04-10',TransferListContext::url($transferContext));
    }
    public function test_tampered_or_malformed_shipment_context_stays_local(): void
    {
        $s=$this->shipment();
        foreach (['https://example.test',['bad'],str_repeat('x',5000)] as $context) {
            $this->get(route('shipments.show',['shipment'=>$s,'context'=>$context]))->assertOk()->assertViewHas('returnUrl',route('shipments.index'));
            $this->get(route('shipments.create',['context'=>$context]))->assertOk()->assertViewHas('returnUrl',route('shipments.index'));
        }
    }
    public function test_shipment_progress_and_next_stop_are_displayed_from_actual_receipts(): void
    {
        $s=$this->shipment(); $service=app(ShipmentService::class); $service->start($s,$this->actor->id,1);
        $service->receive($s,$s->stops()->first(),$this->actor->id);
        $response=$this->get(route('shipments.index',['search'=>$s->shipment_number]))->assertOk()->assertSee('Következő: Cél B')->assertSee('Átvett megállók: 1 / 2');
        $row=$response->viewData('shipments')->sole();
        $this->assertSame(2,$row->stops_count); $this->assertSame(1,$row->received_stops_count);
    }
    public static function shipmentSorts(): array
    {
        $cases=[]; foreach (['created_at','reference','status'] as $key) foreach (['asc','desc'] as $direction) $cases[]=[$key,$direction]; return $cases;
    }
    #[DataProvider('shipmentSorts')]
    public function test_shipment_sorting_is_stable_in_both_directions(string $key,string $direction): void
    {
        $high=$this->shipment('Zeta','2026-04-10 12:00:00');
        $low=$this->shipment('Alpha','2026-04-09 12:00:00'); $low->update(['status'=>'completed']);
        $equal=$this->shipment('Alpha','2026-04-09 12:00:00'); $equal->update(['status'=>'completed']);
        $response=$this->get(route('shipments.index',['sort_by'=>$key,'sort_dir'=>$direction]))->assertOk();
        $this->assertSame($direction === 'asc' ? [$low->id,$equal->id,$high->id] : [$high->id,$equal->id,$low->id],$response->viewData('shipments')->pluck('id')->all());
    }
}
