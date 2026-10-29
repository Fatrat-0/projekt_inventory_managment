<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Services\{InventoryService, ShipmentService};
use App\Support\{WarehouseListContext, WarehouseStockContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseDetailTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;
    private Warehouse $local;
    private Warehouse $other;
    private Product $a;
    private Product $b;
    private Product $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'admin']); $this->actingAs($this->actor);
        $this->local = Warehouse::create(['warehouse_name' => 'Helyi', 'location' => 'Szeged']);
        $this->other = Warehouse::create(['warehouse_name' => 'Másik', 'location' => 'Győr']);
        $category = Category::create(['category_name' => 'Teszt']);
        $make = fn($name, $sku) => Product::create(['product_name' => $name, 'sku' => $sku, 'category_id' => $category->category_id, 'price' => 100]);
        $this->a = $make('Alpha termék', 'SKU-100%_!');
        $this->b = $make('Zeta termék', 'SKU-Z');
        $this->outside = $make('Külső termék', 'SKU-OUTSIDE');
        foreach ([[$this->local,$this->a,3],[$this->local,$this->b,0],[$this->other,$this->a,20],[$this->other,$this->b,9],[$this->other,$this->outside,8]] as [$warehouse,$product,$quantity]) {
            Stock::create(['warehouse_id'=>$warehouse->warehouse_id,'product_id'=>$product->product_id,'quantity'=>$quantity]);
        }
    }

    private function transfer(Warehouse $from, Warehouse $to, Product $product, int $quantity): InventoryTransfer
    {
        return app(InventoryService::class)->startTransfer(['from_warehouse_id'=>$from->warehouse_id,'to_warehouse_id'=>$to->warehouse_id,'product_id'=>$product->product_id,'quantity'=>$quantity,'user_id'=>$this->actor->id]);
    }
    private function show(array $filters = []) { return $this->get(route('warehouses.show', ['warehouse'=>$this->local]+$filters))->assertOk(); }

    public function test_default_stock_is_local_and_route_warehouse_cannot_be_overridden_by_query(): void
    {
        $response = $this->show(['warehouse_id'=>$this->other->warehouse_id]);
        $stocks = $response->viewData('stocks');
        $this->assertSame([$this->a->product_id], $stocks->pluck('product_id')->all());
        $this->assertSame(3, $stocks->first()->quantity);
        $this->assertSame(3, $response->viewData('stockTotals')->quantity);
        $this->assertEquals(1, $response->viewData('stockTotals')->products_count);
        $this->assertSame(0, $response->viewData('incomingTotals')->quantity);
        $this->assertSame(0, $response->viewData('outgoingTotals')->quantity);
    }

    public function test_zero_rows_are_explicit_and_stock_filters_do_not_change_warehouse_totals(): void
    {
        $zero = $this->show(['stock'=>'zero']);
        $this->assertSame([$this->b->product_id], $zero->viewData('stocks')->pluck('product_id')->all());
        $this->assertSame(3, $zero->viewData('stockTotals')->quantity);
        $this->assertSame(2, $this->show(['stock'=>'all'])->viewData('stocks')->total());
    }

    public function test_search_combines_name_and_sku_and_escapes_literal_wildcards(): void
    {
        $this->assertSame(1, $this->show(['search'=>'Alpha 100%_!'])->viewData('stocks')->total());
        $this->assertSame(0, $this->show(['search'=>'Zeta 100%_!','stock'=>'all'])->viewData('stocks')->total());
        $this->assertSame(1, $this->show(['product_id'=>$this->b->product_id,'stock'=>'zero'])->viewData('stocks')->total());
    }

    public function test_only_pending_local_transfers_are_counted_and_outgoing_is_not_deducted_twice(): void
    {
        $service = app(InventoryService::class);
        $incoming = $this->transfer($this->other,$this->local,$this->outside,2);
        $outgoing = $this->transfer($this->local,$this->other,$this->a,1);
        $closed = $this->transfer($this->other,$this->local,$this->b,1); $service->finalizeTransfer($closed,true);
        $cancelled = $this->transfer($this->other,$this->local,$this->outside,1); $service->finalizeTransfer($cancelled,false);
        $third = Warehouse::create(['warehouse_name'=>'Harmadik','location'=>'Pécs']);
        $this->transfer($this->other,$third,$this->b,1);
        app(ShipmentService::class)->save(['reference'=>'Még csak terv','origin_warehouse_id'=>$this->other->warehouse_id,'stops'=>[['warehouse_id'=>$this->local->warehouse_id,'items'=>[['product_id'=>$this->a->product_id,'quantity'=>1]]]]],$this->actor->id);
        $response = $this->show();
        $this->assertSame(3, $response->viewData('stockTotals')->quantity);
        $this->assertEquals(2, $response->viewData('stockTotals')->products_count);
        $this->assertSame(2, $response->viewData('incomingTotals')->quantity);
        $this->assertEquals(1, $response->viewData('incomingTotals')->transfer_count);
        $this->assertSame(1, $response->viewData('outgoingTotals')->quantity);
        $this->assertSame([$incoming->id], $response->viewData('incoming')->pluck('id')->all());
        $this->assertSame([$outgoing->id], $response->viewData('outgoing')->pluck('id')->all());
    }

    public function test_preview_limit_does_not_limit_incoming_summary_and_ties_are_stable(): void
    {
        for ($i=0;$i<6;$i++) $this->transfer($this->other,$this->local,$this->a,1)->update(['created_at'=>'2026-01-01 12:00:00']);
        $response = $this->show();
        $this->assertSame(6, $response->viewData('incomingTotals')->quantity);
        $this->assertEquals(6, $response->viewData('incomingTotals')->transfer_count);
        $this->assertCount(5, $response->viewData('incoming'));
        $this->assertSame([6,5,4,3,2], $response->viewData('incoming')->pluck('id')->all());
    }

    public function test_managed_shipment_items_link_to_the_shipment_and_counts_follow_receipt(): void
    {
        $service = app(ShipmentService::class);
        $shipment = $service->save(['reference'=>'Körjárat','origin_warehouse_id'=>$this->other->warehouse_id,'stops'=>[['warehouse_id'=>$this->local->warehouse_id,'items'=>[['product_id'=>$this->outside->product_id,'quantity'=>2]]]]],$this->actor->id);
        $service->start($shipment,$this->actor->id,1);
        $response = $this->show();
        $response->assertSee(e(route('shipments.show',$shipment)),false)->assertSee($shipment->shipment_number);
        $this->assertSame(2,$response->viewData('incomingTotals')->quantity);
        $service->receive($shipment,$shipment->stops()->first(),$this->actor->id);
        $response = $this->show();
        $this->assertSame(0,$response->viewData('incomingTotals')->quantity);
        $this->assertSame(5,$response->viewData('stockTotals')->quantity);
    }

    public function test_recent_movements_are_local_limited_and_ordered(): void
    {
        for ($i=0;$i<8;$i++) InventoryMovement::create(['product_id'=>$this->a->product_id,'warehouse_id'=>$this->local->warehouse_id,'user_id'=>$this->actor->id,'type'=>'in','quantity'=>1,'reference'=>'LOCAL-'.$i]);
        InventoryMovement::create(['product_id'=>$this->a->product_id,'warehouse_id'=>$this->other->warehouse_id,'user_id'=>$this->actor->id,'type'=>'in','quantity'=>1,'reference'=>'OUTSIDE']);
        $response = $this->show();
        $this->assertCount(6,$response->viewData('movements'));
        $this->assertSame(['LOCAL-7','LOCAL-6','LOCAL-5','LOCAL-4','LOCAL-3','LOCAL-2'],$response->viewData('movements')->pluck('reference')->all());
    }

    public static function stockSorts(): array { return [['product_name'],['sku'],['quantity']]; }
    #[DataProvider('stockSorts')]
    public function test_local_stock_pagination_and_sorts_are_stable(string $sort): void
    {
        $category = Category::first();
        for ($i=0;$i<16;$i++) {
            $product=Product::create(['product_name'=>'Azonos','sku'=>'PAGE-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'price'=>100,'category_id'=>$category->category_id]);
            Stock::create(['warehouse_id'=>$this->local->warehouse_id,'product_id'=>$product->product_id,'quantity'=>1]);
        }
        $response=$this->show(['search'=>'PAGE','sort_by'=>$sort,'per_page'=>15,'page'=>2]);
        $this->assertSame(16,$response->viewData('stocks')->total());
        $this->assertCount(1,$response->viewData('stocks'));
        $this->assertStringContainsString('page=1',$response->viewData('stocks')->previousPageUrl());
    }

    public function test_empty_warehouse_and_reading_do_not_change_business_data(): void
    {
        $empty=Warehouse::create(['warehouse_name'=>'Üres','location'=>null]);
        $before=[Stock::orderBy('id')->get()->toArray(),InventoryTransfer::all()->toArray(),InventoryMovement::all()->toArray()];
        $this->get(route('warehouses.show',$empty))->assertOk()->assertSee('Cím nincs megadva')->assertSee('Nincs úton lévő tétel.')->assertViewHas('stocks',fn($stocks)=>$stocks->total()===0);
        $this->show(['search'=>'Alpha']);
        $this->assertSame($before,[Stock::orderBy('id')->get()->toArray(),InventoryTransfer::all()->toArray(),InventoryMovement::all()->toArray()]);
    }

    public static function invalidFilters(): array { return [['search',['bad']],['search',str_repeat('x',201)],['stock','bad'],['stock',['all']],['sort_by','bad'],['sort_by',['quantity']],['sort_dir','bad'],['per_page',16],['page',0],['product_id',999999],['product_id',[1]]]; }
    #[DataProvider('invalidFilters')]
    public function test_invalid_detail_filters_return_json_errors_or_recover_html(string $key,mixed $value): void
    {
        $params=['warehouse'=>$this->local,$key=>$value];
        $this->getJson(route('warehouses.show',$params))->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->get(route('warehouses.show',$params))->assertRedirect(route('warehouses.show',$this->local))->assertSessionHasErrors('filters');
    }

    public function test_a_product_without_a_local_stock_row_cannot_be_selected_even_if_it_exists_elsewhere(): void
    {
        $this->getJson(route('warehouses.show',['warehouse'=>$this->local,'product_id'=>$this->outside->product_id]))->assertUnprocessable()->assertJsonValidationErrors('product_id');
    }

    public function test_edit_and_cancel_return_to_detail_stock_filters_and_original_warehouse_list_context(): void
    {
        $context=WarehouseListContext::encode(['search'=>'Helyi','sort_by'=>'location','sort_dir'=>'desc','per_page'=>25]);
        $filters=['search'=>'Alpha','stock'=>'all','sort_by'=>'quantity','sort_dir'=>'desc','per_page'=>25,'page'=>1];
        $detailContext=WarehouseStockContext::encode($filters);
        $return=route('warehouses.show',['warehouse'=>$this->local,'context'=>$context]+$filters).'#local-stock';
        $params=['warehouse'=>$this->local,'context'=>$context,'from'=>'details','detail_context'=>$detailContext];
        $this->get(route('warehouses.edit',$params))->assertOk()->assertViewHas('returnUrl',$return);
        $this->put(route('warehouses.update',$this->local),['warehouse_name'=>'Helyi új','location'=>'Új cím','from'=>'details','context'=>$context,'detail_context'=>$detailContext])->assertRedirect($return);
        $this->show(['context'=>$context])->assertViewHas('returnUrl',WarehouseListContext::url($context));
    }

    public function test_detail_context_is_local_and_whitelisted_for_malformed_or_forged_values(): void
    {
        foreach (['bad',['bad'],str_repeat('x',5000)] as $token) $this->assertSame(route('warehouses.show',$this->local).'#local-stock',WarehouseStockContext::url($this->local,$token,''));
        $token=Crypt::encryptString(json_encode(['search'=>'Alpha','warehouse'=>999999,'context'=>'bad','redirect'=>'https://example.com','stock'=>['bad']]));
        $this->assertSame(route('warehouses.show',['warehouse'=>$this->local,'search'=>'Alpha']).'#local-stock',WarehouseStockContext::url($this->local,$token,''));
    }

    public static function roles(): array { return [['admin',200],['manager',200],['worker',403],['invalid',403],[null,401]]; }
    #[DataProvider('roles')]
    public function test_detail_access_matches_warehouse_management_permissions(?string $role,int $status): void
    {
        if ($role===null) auth()->logout(); else { $this->actor->role=$role; $this->actingAs($this->actor); }
        $this->getJson(route('warehouses.show',$this->local))->assertStatus($status);
    }

    public function test_unknown_warehouse_is_not_found(): void { $this->getJson(route('warehouses.show',999999))->assertNotFound(); }

    public static function stalePages(): array { return [[0,1],[2,1],[16,2]]; }

    #[DataProvider('stalePages')]
    public function test_a_page_that_no_longer_exists_recovers_without_losing_filters_or_list_context(int $count,int $lastPage): void
    {
        for ($i=0;$i<$count;$i++) {
            $product=Product::create(['product_name'=>'Lapszél '.$i,'sku'=>'EDGE-PAGE-'.$i,'price'=>100,'category_id'=>Category::first()->category_id]);
            Stock::create(['warehouse_id'=>$this->local->warehouse_id,'product_id'=>$product->product_id,'quantity'=>1]);
        }
        $context=WarehouseListContext::encode(['search'=>'Helyi','per_page'=>25]);
        $params=['warehouse'=>$this->local,'context'=>$context,'search'=>'EDGE-PAGE','stock'=>'all','sort_by'=>'quantity','sort_dir'=>'desc','per_page'=>15,'page'=>999];
        $response=$this->get(route('warehouses.show',$params));
        $response->assertRedirect();
        $url=$response->headers->get('Location');
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        if ($lastPage===1) $this->assertArrayNotHasKey('page',$query); else $this->assertSame((string)$lastPage,$query['page']);
        foreach (['context'=>$context,'search'=>'EDGE-PAGE','stock'=>'all','sort_by'=>'quantity','sort_dir'=>'desc','per_page'=>'15'] as $key=>$value) $this->assertSame($value,$query[$key]);
        $this->get($url)->assertOk()->assertViewHas('stocks',fn($stocks)=>$stocks->total()===$count && $stocks->count()===$count-15*($lastPage-1));
    }
}
