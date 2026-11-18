<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Shipment, ShipmentStop, TransferIntent, User, Warehouse, WarehouseTransferTask};
use App\Services\{ShipmentPlanningService, TransferIntentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferListSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'transfer-intent-pages.index', 'shipments.index', 'warehouse-picking-pages.index',
        'warehouse-receiving-pages.index', 'warehouse-putaway-pages.index',
    ];

    public function test_suggestions_require_authentication_and_the_same_list_permissions(): void
    {
        foreach (self::PAGES as $route) {
            $this->getJson(route($route, ['suggestions' => 1, 'search' => 'DEMO']))->assertUnauthorized();
        }
        $actor = User::factory()->create(['role' => 'worker']);
        $this->actingAs($actor)->getJson(route(self::PAGES[0], ['suggestions' => 1, 'search' => 'DEMO']))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        foreach (array_slice(self::PAGES, 1) as $route) {
            $this->getJson(route($route, ['suggestions' => 1, 'search' => 'DEMO']))->assertForbidden();
        }
    }

    public function test_all_five_lists_return_empty_suggestions_for_empty_input_and_validate_query_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach (self::PAGES as $route) {
            $this->getJson(route($route, ['suggestions' => 1, 'search' => '  ']))
                ->assertOk()->assertExactJson(['items' => [], 'has_more' => false])
                ->assertHeader('Cache-Control', 'no-store, private');
            foreach ([['search' => ['invalid']], ['search' => str_repeat('a', 201)], ['status' => ['invalid']]] as $invalid) {
                $field = array_key_first($invalid);
                $this->getJson(route($route, ['suggestions' => 1] + $invalid))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->get(route(self::PAGES[0], ['suggestions' => 1, 'search' => 'a']))
            ->assertOk()->assertViewIs('transfer-intents.index');
    }

    public function test_intent_suggestions_are_bounded_literal_filtered_ordered_and_not_limited_to_the_current_page(): void
    {
        [$admin, $source, $destination, $product] = $this->smallFixture();
        $this->actingAs($admin);
        $intents = [];
        foreach (range(1, 11) as $quantity) $intents[] = $this->intent($admin, $source, $destination, $product, ['quantity' => $quantity]);
        $before = $this->snapshot();
        $query = [
            'suggestions' => 1, 'search' => '%_', 'warehouse_id' => $source->getKey(), 'status' => 'pending_approval',
            'priority' => 'urgent', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'then_by' => 'reference', 'then_dir' => 'desc',
            'page' => 2, 'per_page' => 1,
        ];
        $response = $this->getJson(route(self::PAGES[0], $query))->assertOk()
            ->assertJsonCount(8, 'items')->assertJsonPath('has_more', true);
        $this->assertSame(array_map(fn ($intent) => $intent->reference, array_slice($intents, 0, 8)), $response->json('items.*.value'));
        $response->assertJsonPath('items.0.name', $product->product_name)
            ->assertJsonPath('items.0.key', 'intent-'.$intents[0]->id);
        $this->assertStringContainsString($product->sku, $response->json('items.0.detail'));
        $this->assertStringContainsString($source->warehouse_name, $response->json('items.0.detail'));
        $this->getJson(route(self::PAGES[0], array_replace($query, ['priority' => 'normal'])))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->get(route(self::PAGES[0], ['search' => $response->json('items.0.value')]))
            ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $intents[0]->id);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_intent_suggestions_do_not_disclose_other_users_records_or_foreign_warehouse_data(): void
    {
        [$admin, $source, $destination, $product] = $this->smallFixture();
        $actor = $this->member($destination, 'requester');
        $mine = $this->intent($actor, $source, $destination, $product);
        $hidden = $this->intent($admin, $source, $destination, $product);
        $foreign = Warehouse::create(['warehouse_name' => 'Idegen raktár']);
        $this->actingAs($actor)->getJson(route(self::PAGES[0], ['suggestions' => 1, 'search' => $product->sku]))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.value', $mine->reference)->assertDontSee($hidden->reference);
        $this->getJson(route(self::PAGES[0], ['suggestions' => 1, 'search' => $product->sku, 'warehouse_id' => $foreign->getKey()]))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
    }

    public function test_shipment_and_picking_suggestions_reuse_warehouse_and_status_scopes(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@raktar.hu')->sole();
        $source = Warehouse::create(['warehouse_name' => 'Javaslat forrás']);
        $outside = Warehouse::create(['warehouse_name' => 'Idegen fuvarforrás']);
        $plan = $this->plan($admin, $source, 'Javaslat fuvar %_');
        $hidden = $this->plan($admin, $outside, 'Javaslat fuvar %_ idegen');
        $actor = $this->member($source, 'supervisor');
        $this->actingAs($actor)->getJson(route(self::PAGES[1], [
            'suggestions' => 1, 'search' => '%_', 'status' => 'draft', 'page' => 2, 'per_page' => 1,
        ]))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.value', $plan->shipment_number)
            ->assertDontSee($hidden->shipment_number);
        $this->getJson(route(self::PAGES[1], ['suggestions' => 1, 'search' => '%_', 'origin_warehouse_id' => $outside->getKey()]))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->getJson(route(self::PAGES[1], ['suggestions' => 1, 'search' => '%_', 'status' => 'in_transit']))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);

        $task = WarehouseTransferTask::with('intent.product')->where('status', 'picked')->firstOrFail();
        $picker = $this->member(Warehouse::findOrFail($task->warehouse_id), 'dispatch_operator');
        $before = $this->snapshot();
        $query = ['suggestions' => 1, 'search' => $task->intent->reference, 'status' => 'picked', 'page' => 2, 'per_page' => 1];
        $this->actingAs($picker)->getJson(route(self::PAGES[2], $query))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.value', $task->intent->reference)
            ->assertJsonPath('items.0.name', $task->intent->product->product_name);
        $this->getJson(route(self::PAGES[2], array_replace($query, ['status' => 'open'])))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->getJson(route(self::PAGES[2], $query + ['warehouse_id' => $outside->getKey()]))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->actingAs($this->member($outside, 'dispatch_operator'))->getJson(route(self::PAGES[2], $query))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_receiving_and_putaway_suggestions_preserve_destination_receipt_and_shipment_filters_without_writes(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@raktar.hu')->sole();
        $this->actingAs($admin);
        $plan = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        $this->postJson(route('shipment-plans.dispatch.store', $plan), [
            'operation_key' => (string) Str::uuid(), 'version' => $plan->version, 'confirmed' => true,
        ])->assertOk();
        [$pecs, $gyor] = $plan->stops()->get()->all();
        $query = ['suggestions' => 1, 'search' => $plan->shipment_number, 'per_page' => 1, 'page' => 2];
        $this->getJson(route(self::PAGES[3], $query))->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('has_more', false);
        $receiver = $this->member($pecs->warehouse, 'receiving_operator');
        $this->actingAs($receiver)->getJson(route(self::PAGES[3], $query))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.key', 'receiving-'.$pecs->id)
            ->assertDontSee($gyor->warehouse->warehouse_name);
        $this->getJson(route(self::PAGES[3], $query + ['warehouse_id' => $gyor->warehouse_id]))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->getJson(route(self::PAGES[3], $query + ['status' => 'received']))
            ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        $this->actingAs($admin);
        $receipt = $this->receive($plan, $pecs);
        $this->receive($plan, $gyor);
        $before = $this->snapshot();
        $this->actingAs($receiver)->getJson(route(self::PAGES[4], $query + ['receipt_id' => $receipt['receipt']['id']]))
            ->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('has_more', false);
        $sku = $pecs->transitItems()->with('product')->orderBy('id')->first()->product->sku;
        $selected = array_replace($query, ['search' => $sku, 'receipt_id' => $receipt['receipt']['id'], 'shipment_id' => $plan->id]);
        $this->getJson(route(self::PAGES[4], $selected))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.value', $sku);
        foreach ([['warehouse_id' => $gyor->warehouse_id], ['shipment_id' => $plan->id + 10000], ['status' => 'completed']] as $filter) {
            $this->getJson(route(self::PAGES[4], array_replace($selected, $filter)))
                ->assertOk()->assertExactJson(['items' => [], 'has_more' => false]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function smallFixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = Warehouse::create(['warehouse_name' => 'Dinamikus központ']);
        $destination = Warehouse::create(['warehouse_name' => 'Dinamikus cél']);
        $category = Category::create(['category_name' => 'Teszt kategória']);
        $product = Product::create(['product_name' => 'Dinamikus próba', 'sku' => 'SUGGEST-%_001', 'price' => 1, 'category_id' => $category->getKey()]);

        return [$admin, $source, $destination, $product];
    }

    private function intent(User $actor, Warehouse $from, Warehouse $to, Product $product, array $extra = []): TransferIntent
    {
        $result = app(TransferIntentService::class)->create($actor, array_replace([
            'operation_key' => (string) Str::uuid(), 'product_id' => $product->getKey(),
            'from_warehouse_id' => $from->getKey(), 'to_warehouse_id' => $to->getKey(), 'quantity' => 1, 'priority' => 'urgent',
        ], $extra));

        return TransferIntent::findOrFail($result['intent']['id']);
    }

    private function plan(User $actor, Warehouse $origin, string $reference): Shipment
    {
        $result = app(ShipmentPlanningService::class)->create($actor, [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $origin->getKey(), 'reference' => $reference,
        ]);

        return Shipment::findOrFail($result['shipment']['id']);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $actor = User::factory()->create(['role' => 'worker']);
        $actor->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $actor;
    }

    private function receive(Shipment $plan, ShipmentStop $stop): array
    {
        return $this->postJson(route('shipment-plans.stops.receipts.store', [$plan, $stop]), [
            'operation_key' => (string) Str::uuid(), 'version' => $stop->fresh()->receiving_version, 'confirmed' => true,
            'items' => $stop->transitItems()->get()->map(fn ($item) => ['transit_item_id' => $item->id, 'quantity' => $item->remaining_quantity])->all(),
        ])->assertCreated()->json();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['stocks', 'inventory_movements', 'transfer_intents', 'transfer_stock_reservations', 'warehouse_transfer_tasks',
            'warehouse_transfer_allocations', 'warehouse_placements', 'shipment_transit_items', 'shipment_receipt_items'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
