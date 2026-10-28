<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Shipment, User, Warehouse};
use App\Services\ShipmentService;
use App\Support\LoadingPlanPdfOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoadingPlanPdfOptionsTest extends TestCase
{
    use RefreshDatabase;
    private Shipment $shipment;
    protected function setUp(): void
    {
        parent::setUp();
        $user=User::factory()->create(['role'=>'worker']); $this->actingAs($user);
        $category=Category::create(['category_name'=>'Teszt']);
        $product=Product::create(['product_name'=>'Rakodott termék','sku'=>'SKU-TITKOS','price'=>10,'category_id'=>$category->category_id]);
        $origin=Warehouse::create(['warehouse_name'=>'Induló','location'=>'ORIGIN-ADDRESS']);
        $target=Warehouse::create(['warehouse_name'=>'Cél','location'=>'TARGET-ADDRESS']);
        $this->shipment=app(ShipmentService::class)->save(['reference'=>'Fuvar PDF','origin_warehouse_id'=>$origin->warehouse_id,'note'=>'PRIVATE-NOTE',
            'stops'=>[['warehouse_id'=>$target->warehouse_id,'items'=>[['product_id'=>$product->product_id,'quantity'=>2]]]]],$user->id);
        $this->shipment->load('origin','stops.warehouse','stops.items.product');
    }
    public function test_optional_sections_can_be_hidden_without_removing_loading_order_and_quantities(): void
    {
        $options=LoadingPlanPdfOptions::DEFAULTS;
        foreach ($options as $key=>$value) if (is_bool($value)) $options[$key]=false;
        $html=view('pdf.loading-plan',['shipment'=>$this->shipment,'options'=>$options])->render();
        foreach (['Lerakodási útvonal','SKU-TITKOS','ORIGIN-ADDRESS','TARGET-ADDRESS','PRIVATE-NOTE','Rakodva','Rakodást végezte'] as $text) $this->assertStringNotContainsString($text,$html);
        foreach (['1. felrakodás · Cél','Rakodott termék','2 db','TERVEZET'] as $text) $this->assertStringContainsString($text,$html);
    }
    public function test_all_optional_sections_and_empty_loading_check_cells_are_rendered(): void
    {
        $options=LoadingPlanPdfOptions::DEFAULTS;
        foreach ($options as $key=>$value) if (is_bool($value)) $options[$key]=true;
        $html=view('pdf.loading-plan',['shipment'=>$this->shipment,'options'=>$options])->render();
        foreach (['Lerakodási útvonal','SKU-TITKOS','ORIGIN-ADDRESS','TARGET-ADDRESS','PRIVATE-NOTE','Rakodva','Rakodást végezte','Ellenőrizte','<td class="loading-check"></td>'] as $text) $this->assertStringContainsString($text,$html);
    }
    public static function orientations(): array { return [['portrait'],['landscape']]; }
    #[DataProvider('orientations')]
    public function test_real_pdf_page_has_requested_orientation(string $orientation): void
    {
        $response=$this->get(route('shipments.pdf',['shipment'=>$this->shipment,'orientation'=>$orientation,'show_loading_column'=>1,'show_signatures'=>1]))->assertOk()->assertHeader('content-type','application/pdf');
        $this->assertSame(1,preg_match('/\/MediaBox\s*\[\s*0(?:\.\d+)?\s+0(?:\.\d+)?\s+([0-9.]+)\s+([0-9.]+)\s*\]/',$response->getContent(),$box));
        $this->assertSame($orientation === 'landscape',(float)$box[1]>(float)$box[2]);
    }
    public function test_preview_and_download_use_identical_validated_options(): void
    {
        $params=['orientation'=>'landscape','show_route'=>0,'show_sku'=>0,'show_addresses'=>0,'show_note'=>0,'show_loading_column'=>1,'show_signatures'=>1];
        $expected=array_map(fn($value)=>is_int($value)?(bool)$value:$value,$params);
        Pdf::shouldReceive('loadView')->twice()->withArgs(fn($view,$data)=>$view === 'pdf.loading-plan' && $data['options'] === $expected)->andReturnSelf();
        Pdf::shouldReceive('setPaper')->twice()->with('a4','landscape')->andReturnSelf();
        Pdf::shouldReceive('stream')->once()->andReturn(response('preview'));
        Pdf::shouldReceive('download')->once()->andReturn(response('download'));
        $this->get(route('shipments.pdf',['shipment'=>$this->shipment,'preview'=>1]+$params))->assertOk();
        $this->get(route('shipments.pdf',['shipment'=>$this->shipment]+$params))->assertOk();
    }
    public static function invalidOptions(): array
    {
        return [['orientation','bad'],['orientation',['bad']],['show_route',2],['show_sku','bad'],['show_addresses',['bad']],['show_note','bad'],['show_loading_column','bad'],['show_signatures','bad']];
    }
    #[DataProvider('invalidOptions')]
    public function test_invalid_pdf_options_are_rejected(string $key,mixed $value): void
    {
        $this->getJson(route('shipments.pdf',['shipment'=>$this->shipment,$key=>$value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }
}
