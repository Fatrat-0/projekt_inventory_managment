<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, TransferIntent, User, Warehouse, WarehouseStorageZone};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehousePickingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $source;
    private Warehouse $destination;
    private Warehouse $other;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->source = Warehouse::create(['warehouse_name' => 'Központi forrásraktár']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Déli célraktár']);
        $this->other = Warehouse::create(['warehouse_name' => 'Másik forrásraktár']);
        $category = Category::create(['category_name' => 'Csapágyak']);
        $this->product = Product::create([
            'category_id' => $category->getKey(), 'sku' => 'WORK-P-01',
            'product_name' => 'Ipari golyóscsapágy', 'price' => 1,
        ]);
        foreach ([$this->source, $this->other] as $warehouse) {
            Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200]);
        }
        $this->actingAs($this->admin);
    }

    public function test_work_list_and_direct_detail_use_source_warehouse_permissions(): void
    {
        $visible = $this->task($this->source);
        $hidden = $this->task($this->other);
        $notYetTask = $this->approvedIntent($this->source);
        $list = route('warehouse-picking-pages.index');

        $this->get($list)->assertOk()->assertSee($visible->reference)->assertSee($hidden->reference)
            ->assertDontSee($notYetTask->reference);
        foreach ([$this->member($this->source, 'dispatch_operator'), $this->member($this->source, 'supervisor')] as $operator) {
            $this->actingAs($operator)->get($list)->assertOk()->assertSee($visible->reference)
                ->assertDontSee($hidden->reference)->assertSee($this->product->sku);
            $this->get($this->detail($visible))->assertOk()->assertSee($visible->reference)->assertSee('data-picking-task', false);
            $this->get($this->detail($hidden))->assertForbidden();
            $this->get($this->detail($notYetTask))->assertNotFound();
            $this->get(route('warehouse-picking-pages.index', ['warehouse_id' => $this->other->getKey()]))
                ->assertOk()->assertDontSee($hidden->reference);
        }
        foreach ([$this->member($this->source, 'requester'), $this->member($this->destination, 'receiving_operator')] as $readOnly) {
            $this->actingAs($readOnly)->get($list)->assertForbidden();
            $this->get($this->detail($visible))->assertForbidden();
            $this->postJson(route('transfer-intents.picking-task.picks', $visible), $this->pick($visible, null, 1))
                ->assertForbidden();
        }
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_filters_match_sku_reference_status_and_source_warehouse(): void
    {
        $pending = $this->task($this->source);
        $finished = $this->task($this->source);
        $other = $this->task($this->other);
        $this->postJson(route('transfer-intents.picking-task.picks', $finished), $this->pick($finished, null, 10) + [
            'unassigned_confirmation' => ['found' => true, 'description' => 'Kiadási terület mellett.'],
        ])
            ->assertOk()->assertJsonPath('task.status', 'picked');

        $this->get(route('warehouse-picking-pages.index', [
            'search' => $this->product->sku, 'status' => 'pending', 'warehouse_id' => $this->source->getKey(),
        ]))->assertOk()->assertSee($pending->reference)->assertDontSee($finished->reference)->assertDontSee($other->reference);
        $this->get(route('warehouse-picking-pages.index', [
            'search' => $finished->reference, 'status' => 'picked',
        ]))->assertOk()->assertSee($finished->reference)->assertDontSee($pending->reference);
    }

    public function test_a_cancelled_task_stays_read_only_when_the_same_intent_has_a_new_task(): void
    {
        $intent = $this->task($this->source);
        $oldTask = $intent->pickingTasks()->sole();
        $this->patchJson(route('transfer-intents.stock-reservation.release', $intent), $this->command($intent))->assertOk();
        $this->postJson(route('transfer-intents.stock-reservation.store', $intent), $this->command($intent->refresh()))->assertCreated();
        $this->postJson(route('transfer-intents.picking-task.store', $intent), $this->command($intent->refresh()))->assertCreated();
        $newTask = $intent->pickingTasks()->latest('id')->firstOrFail();

        $old = ['transferIntent' => $intent, 'task' => $oldTask->id];
        $new = ['transferIntent' => $intent, 'task' => $newTask->id];
        $this->get(route('warehouse-picking-pages.index'))->assertOk()
            ->assertSee(route('warehouse-picking-pages.show', $old))->assertSee(route('warehouse-picking-pages.show', $new));
        $this->get(route('warehouse-picking-pages.show', $old))->assertOk()->assertSee('Feladat #'.$oldTask->id);
        $this->getJson(route('transfer-intents.picking-task.show', $old))->assertOk()->assertJsonPath('task.id', $oldTask->id)
            ->assertJsonPath('task.status', 'cancelled')->assertJsonPath('can_execute', false);
        $this->getJson(route('transfer-intents.picking-task.recommendations', $old))->assertConflict();
        $this->getJson(route('transfer-intents.picking-task.show', $new))->assertOk()->assertJsonPath('task.id', $newTask->id)
            ->assertJsonPath('task.status', 'pending')->assertJsonPath('can_execute', true);
        $foreign = $this->task($this->other)->pickingTasks()->sole();
        $this->get(route('warehouse-picking-pages.show', ['transferIntent' => $intent, 'task' => $foreign->id]))->assertNotFound();
    }

    public function test_read_only_plan_link_is_scoped_to_positioned_current_or_historical_sources(): void
    {
        $stock = Stock::where('warehouse_id', $this->source->getKey())->sole();
        $zone = WarehouseStorageZone::create(['warehouse_id' => $this->source->getKey(), 'name' => 'Északi szerelési zóna']);
        $rack = $zone->systems()->create([
            'name' => 'Szerelősor állványa', 'kind' => 'rack', 'code' => 'SZ-RACK',
            'max_weight_grams' => 150000, 'floor_x' => 1, 'floor_y' => 1, 'floor_width' => 3, 'floor_height' => 2,
        ]);
        $level = $rack->levels()->create(['number' => 1, 'max_weight_grams' => 100000]);
        $bin = $this->source->storageLocations()->make([
            'code' => 'SZ-RACK-01-01', 'name' => 'Szerelősor rekesze', 'kind' => 'shelf', 'max_weight_grams' => 50000,
        ])->forceFill([
            'system_id' => $rack->id, 'level_id' => $level->id, 'bin_number' => 1,
        ]);
        $bin->save();
        $unrelated = $this->source->storageLocations()->make([
            'code' => 'EMPTY-01', 'name' => 'Másik rekesz', 'kind' => 'shelf', 'max_weight_grams' => 50000,
        ])->forceFill([
            'floor_x' => 6, 'floor_y' => 1, 'floor_width' => 2, 'floor_height' => 2,
        ]);
        $unrelated->save();
        $foreign = $this->other->storageLocations()->create([
            'code' => 'FOREIGN-01', 'name' => 'Idegen rekesz', 'kind' => 'shelf', 'max_weight_grams' => 50000,
            'floor_x' => 1, 'floor_y' => 1, 'floor_width' => 2, 'floor_height' => 2,
        ]);
        $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => 10, 'cargo_weight_grams' => 1000]);
        $intent = $this->task($this->source);
        $plan = route('warehouse-picking-pages.plan', ['transferIntent' => $intent, 'warehouseLocation' => $bin, 'task' => $intent->pickingTasks()->sole()->id]);
        $recommendationUrl = route('transfer-intents.picking-task.recommendations', $intent);

        $this->actingAs($this->member($this->source, 'dispatch_operator'));
        $sources = $this->getJson($recommendationUrl)->assertOk()->assertJsonPath('version', $intent->version)->json('sources');
        $matched = collect($sources)->firstWhere('location_id', $bin->id);
        $this->assertSame($plan, $matched['plan_url']);
        $this->assertStringContainsString('SZ-RACK', $matched['position_label']);
        $this->assertNull(collect($sources)->firstWhere('source_type', 'unassigned')['plan_url']);
        $this->get($plan)->assertOk()->assertSee($bin->code)->assertSee('Raktári tervrajz')
            ->assertDontSee('Tervrajz szerkesztése')->assertDontSee('Tervrajz mentése')
            ->assertViewHas('plan', function (array $plan) use ($rack, $unrelated, $bin) {
                $system = $plan['systems']->firstWhere('id', $rack->id);
                $tile = $plan['locations']->firstWhere('id', $unrelated->id);

                return $plan['focus'] === 'system-'.$rack->id && $plan['focusBin'] === $bin->id
                    && $system['levels'][0]['bins'][0] === ['id' => $bin->id, 'code' => $bin->code]
                    && !array_key_exists('occupied', $system) && !array_key_exists('limit', $system['levels'][0])
                    && !array_key_exists('freeSlots', $tile) && !array_key_exists('products', $tile)
                    && !array_key_exists('url', $plan);
            });
        foreach ([$unrelated, $foreign] as $outside) {
            $this->get(route('warehouse-picking-pages.plan', [$intent, $outside]))->assertNotFound();
        }

        $actor = auth()->user();
        $this->postJson(route('transfer-intents.picking-task.picks', $intent), $this->pick($intent, $bin->id, 10))
            ->assertOk()->assertJsonPath('task.status', 'picked');
        $this->get($plan)->assertOk()->assertSee($bin->code);
        $this->getJson(route('transfer-intents.picking-task.show', $intent))->assertOk()
            ->assertJsonPath('allocations.0.location_code', $bin->code)->assertJsonPath('allocations.0.performer_name', $actor->name);
        $this->actingAs($this->member($this->destination, 'receiving_operator'))->get($plan)->assertForbidden();

        $this->assertSame(200, $stock->refresh()->quantity);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $user;
    }

    private function approvedIntent(Warehouse $warehouse): TransferIntent
    {
        $id = $this->postJson(route('transfer-intents.store'), [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $warehouse->getKey(), 'to_warehouse_id' => $this->destination->getKey(), 'quantity' => 10,
        ])->assertCreated()->json('intent.id');
        $intent = TransferIntent::findOrFail($id);
        $this->patchJson(route('transfer-intents.approve', $intent), $this->command($intent))->assertOk();

        return $intent->refresh();
    }

    private function task(Warehouse $warehouse): TransferIntent
    {
        $intent = $this->approvedIntent($warehouse);
        $this->postJson(route('transfer-intents.stock-reservation.store', $intent), $this->command($intent))->assertCreated();
        $this->postJson(route('transfer-intents.picking-task.store', $intent), $this->command($intent->refresh()))->assertCreated();

        return $intent->refresh();
    }

    private function command(TransferIntent $intent): array
    {
        return ['operation_key' => (string) Str::uuid(), 'version' => $intent->version];
    }

    private function pick(TransferIntent $intent, ?int $location, int $quantity): array
    {
        return $this->command($intent) + ['quantity' => $quantity, 'source_allocations' => [[
            'source_type' => $location === null ? 'unassigned' : 'location', 'location_id' => $location, 'quantity' => $quantity,
        ]]];
    }

    private function detail(TransferIntent $intent): string
    {
        return route('warehouse-picking-pages.show', $intent);
    }
}
