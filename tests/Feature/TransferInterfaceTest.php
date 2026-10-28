<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use App\Support\TransferListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferInterfaceTest extends TestCase
{
    use RefreshDatabase;
    private Product $product;
    private Warehouse $source;
    private Warehouse $target;
    private User $actor;
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'worker']); $this->actingAs($this->actor);
        $category = Category::create(['category_name' => 'Teszt']);
        $this->product = Product::create(['product_name' => 'Alpha csavar', 'sku' => 'SKU_100%', 'price' => 10, 'category_id' => $category->category_id]);
        $this->source = Warehouse::create(['warehouse_name' => 'Induló', 'location' => 'A']);
        $this->target = Warehouse::create(['warehouse_name' => 'Cél', 'location' => 'B']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->source->warehouse_id, 'quantity' => 10]);
    }
    private function payload(array $data = []): array { return array_replace(['request_key' => (string)Str::uuid(), 'product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => 4], $data); }
    public function test_repeated_start_uses_original_transfer_even_after_source_is_empty(): void
    {
        $data = $this->payload(['quantity' => 10]);
        $this->post(route('transfers.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('transfers.store'), array_replace($data, ['request_key' => strtoupper($data['request_key'])]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_transfers', 1); $this->assertDatabaseCount('inventory_transfer_requests', 1);
        $this->assertSame(0, Stock::sole()->quantity);
        $this->postJson(route('transfers.store'), array_replace($data, ['quantity' => 1]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
    }
    public function test_failure_releases_request_key_for_retry(): void
    {
        $data = $this->payload(['quantity' => 11]);
        $this->postJson(route('transfers.store'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_transfer_requests', 0);
        Stock::query()->update(['quantity' => 20]);
        $this->post(route('transfers.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9, Stock::sole()->quantity);
    }
    public function test_context_is_preserved_after_create_and_complete_and_invalid_context_is_safe(): void
    {
        $context = TransferListContext::encode(['status' => 'pending', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 25, 'page' => 2]);
        $url = TransferListContext::url($context);
        $this->get(route('transfers.create', ['context' => $context]))->assertOk()->assertViewHas('returnUrl', $url);
        $this->post(route('transfers.store'), $this->payload(['context' => $context]))->assertRedirect($url);
        $transfer = InventoryTransfer::sole();
        $this->patch(route('transfers.complete', $transfer), ['context' => $context])->assertRedirect($url);
        foreach (['https://example.test', ['bad']] as $bad) {
            $this->get(route('transfers.show', ['transfer' => $transfer, 'context' => $bad]))->assertOk()->assertViewHas('returnUrl', route('transfers.index'));
        }
    }
    public function test_filtered_summary_covers_all_pages_and_multiword_search_escapes_wildcards(): void
    {
        for ($i=0; $i<12; $i++) InventoryTransfer::create(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => 2, 'status' => 'pending', 'user_id' => $this->actor->id]);
        InventoryTransfer::create(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => 99, 'status' => 'completed', 'user_id' => $this->actor->id]);
        $params = ['search' => 'Alpha SKU_100% Cél', 'status' => 'pending', 'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'product_id' => $this->product->product_id, 'per_page' => 10, 'page' => 2];
        $response = $this->get(route('transfers.index', $params))->assertOk();
        $this->assertSame(12, $response->viewData('transfers')->total());
        $this->assertCount(2, $response->viewData('transfers'));
        $this->assertSame(24, $response->viewData('pendingQuantity'));
        $this->get(route('transfers.index', ['search' => 'nincsilyen']))->assertOk()->assertSee('Nincs a feltételeknek megfelelő átmozgatás.');
    }
    public static function sorting(): array
    {
        $cases=[]; foreach (['created_at','product_name','quantity','status'] as $field) foreach (['asc','desc'] as $dir) $cases[] = [$field,$dir]; return $cases;
    }
    #[DataProvider('sorting')]
    public function test_sorting_is_stable_with_equal_values(string $field, string $direction): void
    {
        $first = InventoryTransfer::create(['product_id' => $this->product->product_id, 'from_warehouse_id' => $this->source->warehouse_id, 'to_warehouse_id' => $this->target->warehouse_id, 'quantity' => 2, 'status' => 'pending', 'user_id' => $this->actor->id]);
        $second = $first->replicate(); $second->save(); $second->forceFill(['created_at' => $first->created_at])->save();
        $response = $this->get(route('transfers.index', ['sort_by' => $field, 'sort_dir' => $direction]))->assertOk();
        $this->assertSame($direction === 'asc' ? [$first->id,$second->id] : [$second->id,$first->id], $response->viewData('transfers')->pluck('id')->all());
    }
    public function test_invalid_filters_and_request_keys_are_rejected(): void
    {
        foreach (['status'=>'bad', 'sort_by'=>'bad', 'per_page'=>3, 'page'=>0, 'product_id'=>9999, 'from_warehouse_id'=>9999, 'to_warehouse_id'=>9999, 'search'=>['bad']] as $key => $value) {
            $this->getJson(route('transfers.index', [$key=>$value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        foreach ([null,'bad',['bad']] as $key) $this->postJson(route('transfers.store'), $this->payload(['request_key'=>$key]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
    }
    public function test_start_request_keys_are_scoped_to_actor(): void
    {
        $data = $this->payload(); $this->post(route('transfers.store'), $data)->assertRedirect();
        $this->actingAs(User::factory()->create(['role'=>'worker']))->post(route('transfers.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('inventory_transfers', 2); $this->assertDatabaseCount('inventory_transfer_requests', 2);
        $this->assertSame(2, Stock::sole()->quantity);
    }
}
