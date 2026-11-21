<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, TransferIntent, User, Warehouse, WarehouseLocation, WarehousePlacement, WarehouseRelocation};
use App\Services\{TransferIntentService, TransferStockReservationService, WarehouseStockAvailability, WarehouseStorageDesigner, WarehouseTransferTaskService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseRelocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Stock $stock;
    private WarehouseLocation $from;
    private WarehouseLocation $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['category_id' => Category::create(['category_name' => 'Szerelvény'])->getKey()]);
        $this->stock = Stock::create($this->identity() + ['quantity' => 100]);
        $this->from = $this->bin('A-01');
        $this->to = $this->bin('B-01');
    }

    public function test_an_entire_source_is_moved_with_its_recorded_load_and_a_stable_immutable_history(): void
    {
        $first = $this->place($this->from, 20, 5000);
        $second = $this->place($this->from, 20, 7000);
        $other = $this->bin('A-02');
        $untouched = $this->place($other, 60, 30000);
        $before = $this->totals();
        $command = $this->command(40) + ['reason' => '  Átrendezés  ', 'note' => '0'];
        $response = $this->postJson($this->url('store'), $command)->assertCreated()
            ->assertJsonPath('relocation.load_source', 'recorded')->assertJsonPath('relocation.cargo_weight_grams', 12000)
            ->assertJsonPath('relocation.pallet_slots', 0)->assertJsonPath('relocation.geometry_status', 'verified')
            ->assertJsonPath('relocation.balances', ['warehouse_physical_quantity' => 100, 'warehouse_pickable_quantity' => 100,
                'from_before' => 40, 'from_after' => 0, 'to_before' => 0, 'to_after' => 40])
            ->assertJsonPath('relocation.reason', 'Átrendezés')->assertJsonPath('relocation.note', '0')
            ->assertJsonPath('relocation.actor_id', $this->admin->id)->assertJsonMissingPath('relocation.request_hash');
        $id = $response->json('relocation.id');
        $this->assertStringStartsWith('REK-', $response->json('relocation.reference'));
        $this->assertSame([$first->id, $second->id], array_column($response->json('relocation.source_allocations'), 'placement_id'));
        $this->assertFalse($this->from->placements()->exists());
        $this->assertSame(40, $this->to->placements()->sole()->quantity);
        $this->assertSame(60, $untouched->refresh()->quantity);
        $this->assertSame($before, $this->totals());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->postJson($this->url('store'), $command)->assertCreated()->assertExactJson($response->json());
        $this->postJson($this->url('store'), array_replace($command, ['note' => 'Másik művelet']))->assertConflict();
        $this->assertDatabaseCount('warehouse_relocations', 1);

        $this->from->update(['code' => 'ÚJ-A', 'name' => 'Átnevezett']);
        $this->to->update(['code' => 'ÚJ-B']);
        $this->product->update(['product_name' => 'Új terméknév']);
        $this->from->delete();
        $this->getJson($this->url('show', $id))->assertOk()->assertExactJson($response->json());
        $this->getJson($this->url('index', ['search' => 'A-01', 'per_page' => 1]))
            ->assertOk()->assertJsonPath('data.0.to_location_code', 'B-01')->assertJsonPath('total', 1);
        foreach ([
            fn () => WarehouseRelocation::whereKey($id)->update(['quantity' => 1]),
            fn () => WarehouseRelocation::whereKey($id)->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('Expected immutable relocation history.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('áthelyezési naplóbejegyzés', $error->getMessage());
            }
        }
    }

    public function test_partial_load_needs_measurements_retains_source_capacity_and_requires_site_checks_when_needed(): void
    {
        $original = $this->place($this->from, 40, 18000);
        $this->to->forceFill(['height_cm' => null])->save();
        $command = $this->command(15);
        $this->postJson($this->url('preview'), $command)->assertOk()->assertJsonPath('can_relocate', false)
            ->assertJsonPath('cargo.pallet_slots', null)->assertJsonPath('cargo.cargo_weight_grams', null)
            ->assertJsonStructure(['blocking_reasons' => ['weight_input', 'pallet_kind', 'location_dimensions']]);
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors(['cargo_weight_kg', 'pallet_slots']);
        $this->postJson($this->url('store'), $command + ['cargo_weight_kg' => 19, 'pallet_slots' => 0, 'site_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $measured = $command + ['cargo_weight_kg' => 4.575, 'pallet_slots' => 0];
        $this->postJson($this->url('store'), $measured)->assertUnprocessable()->assertJsonValidationErrors('site_verified');
        $this->postJson($this->url('preview'), $measured + ['site_verified' => true])->assertOk()->assertJsonPath('can_relocate', true)
            ->assertJsonPath('source_allocations.0.retained_weight_grams', 18000);
        $this->postJson($this->url('store'), $measured + ['site_verified' => true])->assertCreated()
            ->assertJsonPath('relocation.geometry_status', 'site_verified')->assertJsonPath('relocation.cargo_weight_grams', 4575)
            ->assertJsonPath('relocation.source_allocations.0.remaining_load_requires_verification', true);
        $this->assertSame([25, 18000, true], [$original->refresh()->quantity, $original->cargo_weight_grams, $original->load_requires_verification]);
        $this->assertSame([15, 4575, false], array_values($this->to->placements()->sole()->only('quantity', 'cargo_weight_grams', 'load_requires_verification')));
        $this->assertSame(100, $this->stock->refresh()->quantity);

        $command = $this->command(25, to: $this->bin('B-02')) + ['cargo_weight_kg' => 12, 'pallet_slots' => 0];
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors('source_load_verified');
        $this->postJson($this->url('store'), $command + ['source_load_verified' => true])->assertCreated()
            ->assertJsonPath('relocation.load_source', 'measured')->assertJsonPath('relocation.source_load_verified', true);
        $this->assertDatabaseCount('warehouse_relocations', 2);
    }

    public function test_a_partial_pallet_move_counts_explicit_slots_and_releases_none_from_the_remainder(): void
    {
        $this->from->update(['kind' => 'pallet', 'max_pallet_slots' => 3]);
        $this->to->update(['kind' => 'pallet', 'max_pallet_slots' => 1]);
        $pallets = $this->place($this->from, 40, 15000, 2);
        $partial = $this->command(10) + ['cargo_weight_kg' => 6];
        $this->postJson($this->url('store'), $partial + ['pallet_slots' => 2])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->postJson($this->url('store'), $partial + ['pallet_slots' => 0])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->postJson($this->url('store'), $partial + ['pallet_slots' => 1])->assertCreated()->assertJsonPath('relocation.pallet_slots', 1);
        $this->assertSame(2, $pallets->refresh()->pallet_slots);
        $this->assertSame(15000, $pallets->cargo_weight_grams);
        $this->assertSame(1, $this->to->placements()->sole()->pallet_slots);
    }

    public function test_shared_rack_advice_and_write_credit_only_fully_removed_load_and_enforce_category_and_geometry(): void
    {
        [$source, $target] = $this->rack();
        $this->place($source, 40, 20000);
        $command = $this->command(40, $source, $target);
        $before = $this->totals();
        $this->postJson($this->url('candidates'), $command)->assertOk()
            ->assertJsonPath('recommendations.0.status', 'verified');
        $result = $this->postJson($this->url('candidates'), $command + ['mode' => 'search'])->assertOk();
        $this->assertNotContains($source->id, array_column($result->json('locations'), 'location_id'));
        $this->postJson($this->url('preview'), $command)->assertOk()
            ->assertJsonPath('location.location_id', $target->id)->assertJsonPath('can_relocate', true);
        $partial = $this->command(15, $source, $target) + ['cargo_weight_kg' => 5, 'pallet_slots' => 0];
        $this->postJson($this->url('preview'), $partial)->assertOk()->assertJsonPath('can_relocate', false)
            ->assertJsonStructure(['blocking_reasons' => ['level_weight', 'system_weight']]);
        $this->postJson($this->url('store'), $partial)->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->assertSame(40, $source->placements()->sole()->quantity);
        $otherCategory = Category::create(['category_name' => 'Más kategória']);
        $target->allowedCategories()->sync([$otherCategory->getKey()]);
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $target->allowedCategories()->detach();
        $oversize = array_replace($command, ['dimensions_cm' => ['width' => 1000], 'site_verified' => true]);
        $this->postJson($this->url('store'), $oversize)->assertUnprocessable()->assertJsonValidationErrors('dimensions_cm');
        $this->assertDatabaseCount('warehouse_relocations', 0);
        $this->assertSame($before, $this->totals());
        $this->postJson($this->url('store'), $command)->assertCreated();
        $this->assertSame($before, $this->totals());
        $this->assertSame(20000, $target->placements()->sole()->cargo_weight_grams);
    }

    public function test_stock_preview_accounts_for_moves_inside_a_level_between_levels_and_between_racks(): void
    {
        [$source, $sameLevel, $otherLevel] = $this->rack('POLC-A', levels: 2, limit: 100);
        [$otherRack] = $this->rack('POLC-B', x: 3, limit: 100);
        $this->place($source, 10, 10000);
        $this->place($sameLevel, 6, 6000);
        $this->place($otherLevel, 4, 4000);
        $this->place($otherRack, 11, 11000);
        $baseline = $this->totals();

        foreach ([
            [$sameLevel, 4, [6, 10], [16, 16], [20, 20]],
            [$otherLevel, 4, [4, 8], [4, 8], [20, 20]],
            [$otherRack, 10, [11, 21], [11, 21], [11, 21]],
        ] as [$target, $quantity, $bin, $level, $rack]) {
            $input = $this->command($quantity, $source, $target) + ['site_verified' => true];
            if ($quantity < 10) $input += ['cargo_weight_kg' => 4, 'pallet_slots' => 0];
            $expected = [
                'bin' => array_combine(['before', 'after'], $bin),
                'level' => array_combine(['before', 'after'], $level),
                'system' => array_combine(['before', 'after'], $rack) + ['kind' => 'rack'],
            ];
            $this->postJson($this->url('candidates'), $input + ['mode' => 'search'])->assertOk()
                ->assertJsonPath('locations.0.status', 'conditional')->assertJsonPath('locations.0.product_stock', $expected);
            $this->postJson($this->url('preview'), $input)->assertOk()->assertJsonPath('can_relocate', true)
                ->assertJsonPath('location.product_stock', $expected);
        }

        $this->assertSame($baseline, $this->totals());
        $this->assertDatabaseCount('warehouse_relocations', 0);
    }

    public function test_location_status_identity_capacity_quantity_and_source_revision_are_rechecked(): void
    {
        $placement = $this->place($this->from, 40, 18000);
        $foreign = $this->bin('KÜLSŐ', warehouse: Warehouse::factory()->create());
        $command = $this->command(40);
        $this->postJson($this->url('store'), array_replace($command, ['to_location_id' => (string) $this->from->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->postJson($this->url('store'), array_replace($command, ['to_location_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->postJson($this->url('store'), array_replace($command, ['quantity' => 41]))->assertConflict()->assertJsonValidationErrors('quantity');
        $this->postJson($this->url('store'), array_replace($command, ['quantity' => true]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->from->update(['active' => false]);
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors('from_location_id');
        $this->from->update(['active' => true]);
        $this->to->update(['active' => false]);
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->to->update(['active' => true, 'max_weight_grams' => 17999]);
        $this->postJson($this->url('store'), $command)->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $snapshot = $this->getJson($this->url('sources', $this->identity() + ['from_location_id' => $this->from->id]))->assertOk()
            ->assertJsonPath('source.movable_quantity', 40)->assertJsonPath('source.recorded_weight_grams', 18000);
        $placement->update(['cargo_weight_grams' => 15000]);
        $this->postJson($this->url('store'), $command + ['source_revision' => $snapshot->json('source.source_revision')])->assertConflict();
        $this->assertDatabaseCount('warehouse_relocations', 0);
        $this->assertSame(40, $placement->refresh()->quantity);
        $this->assertFalse($this->to->placements()->exists());
    }

    public function test_reserved_staged_quarantined_and_pending_quantity_remain_protected(): void
    {
        $this->stock->update(['quarantined_quantity' => 5]);
        $this->place($this->from, 40, 8000);
        $this->place($this->bin('A-02'), 20, 4000);
        $receipt = $this->postJson(route('movements.store'), $this->identity() + [
            'type' => 'in', 'confirmed' => true, 'request_key' => (string) Str::uuid(), 'quantity' => 30,
        ])->assertCreated();
        $intents = app(TransferIntentService::class);
        $intent = TransferIntent::findOrFail($intents->create($this->admin, [
            'from_warehouse_id' => $this->warehouse->getKey(), 'to_warehouse_id' => Warehouse::factory()->create()->getKey(),
            'product_id' => $this->product->getKey(), 'quantity' => 75, 'operation_key' => (string) Str::uuid(),
        ])['intent']['id']);
        $intents->approve($this->admin, $intent, $this->version($intent));
        app(TransferStockReservationService::class)->reserve($this->admin, $intent, $this->version($intent));
        $picking = app(WarehouseTransferTaskService::class);
        $picking->create($this->admin, $intent, $this->version($intent));
        $picking->pick($this->admin, $intent, $this->version($intent) + [
            'quantity' => 20, 'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => 20]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A kiadási területen található.'],
        ]);
        $expected = ['physical_quantity' => 130, 'quarantined_quantity' => 5, 'staged_quantity' => 20,
            'pending_putaway_quantity' => 30, 'source_reserved_quantity' => 55, 'pickable_quantity' => 20];
        $this->assertSame($expected, $this->totals());
        $this->getJson($this->url('sources', $this->identity()))->assertOk()->assertJsonPath('sources.0.movable_quantity', 20);
        $this->postJson($this->url('store'), $this->command(21) + ['cargo_weight_kg' => 4, 'pallet_slots' => 0])->assertConflict();
        $this->postJson($this->url('store'), $this->command(20) + ['cargo_weight_kg' => 4, 'pallet_slots' => 0])->assertCreated();
        $this->assertSame($expected, $this->totals());
        $this->assertDatabaseHas('manual_receipt_batches', ['id' => $receipt->json('batch.id'), 'pending_putaway_quantity' => 30]);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_audit_failure_rolls_back_source_destination_and_allows_the_same_key_to_retry(): void
    {
        $placement = $this->place($this->from, 40, 18000);
        $command = $this->command(15) + ['cargo_weight_kg' => 4, 'pallet_slots' => 0];
        DB::unprepared("CREATE TRIGGER reject_relocation_test BEFORE INSERT ON warehouse_relocations BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try {
            $this->withoutExceptionHandling();
            $this->postJson($this->url('store'), $command);
            $this->fail('Expected the audit write to fail.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('audit failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_relocation_test');
            $this->withExceptionHandling();
        }

        $this->assertSame(40, $placement->refresh()->quantity);
        $this->assertSame(18000, $placement->cargo_weight_grams);
        $this->assertFalse($placement->load_requires_verification);
        $this->assertFalse($this->to->placements()->exists());
        $this->assertSame(100, $this->stock->refresh()->quantity);
        $this->assertDatabaseCount('warehouse_relocations', 0);
        $this->postJson($this->url('store'), $command)->assertCreated();
    }

    public function test_every_endpoint_checks_warehouse_permissions_and_source_search_uses_only_movable_assigned_stock(): void
    {
        $this->place($this->from, 40, 10000);
        $foreign = Warehouse::factory()->create();
        $worker = $this->member('receiving_operator', $this->warehouse);
        $this->actingAs($worker)->getJson($this->url('sources'))->assertOk()->assertJsonCount(1, 'warehouses')
            ->assertJsonPath('warehouses.0.warehouse_id', $this->warehouse->getKey());
        $this->getJson($this->url('sources', ['warehouse_id' => $this->warehouse->getKey(), 'search' => $this->product->sku]))
            ->assertOk()->assertJsonPath('products.data.0.product_id', $this->product->getKey());
        $this->getJson($this->url('sources', ['warehouse_id' => $foreign->getKey()]))->assertForbidden();
        $command = $this->command(40);
        $event = $this->postJson($this->url('store'), $command)->assertCreated()->assertJsonPath('relocation.actor_id', $worker->id);
        $eventId = $event->json('relocation.id');
        foreach ([$this->member('requester', $this->warehouse), $this->member('dispatch_operator', $foreign)] as $actor) {
            $this->actingAs($actor)->getJson($this->url('sources', $this->identity()))->assertForbidden();
            foreach (['candidates', 'preview', 'store'] as $method) {
                $this->postJson($this->url($method), $command)->assertForbidden();
            }
            $this->getJson($this->url('index', ['warehouse_id' => $this->warehouse->getKey()]))->assertForbidden();
            $this->getJson($this->url('show', $eventId))->assertForbidden();
        }

        $this->actingAs($this->member('dispatch_operator', $this->warehouse))
            ->getJson($this->url('show', $eventId))->assertOk();
        $this->assertDatabaseCount('warehouse_relocations', 1);
    }

    public function test_workbench_scopes_both_picker_directions_and_exposes_only_live_bins_for_links(): void
    {
        $this->place($this->from, 40, 16000);
        $this->to->forceFill(['floor_x' => 2, 'floor_y' => 2, 'floor_width' => 1, 'floor_height' => 1])->save();
        $remote = Warehouse::factory()->create();
        $remoteBin = $this->bin('TÁVOLI', warehouse: $remote);
        $remoteStock = Stock::create(['warehouse_id' => $remote->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 12]);
        $remoteBin->placements()->create(['stock_id' => $remoteStock->id, 'quantity' => 12, 'cargo_weight_grams' => 3000]);
        $noBin = Product::factory()->create(['category_id' => $this->product->category_id]);
        Stock::create(['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $noBin->getKey(), 'quantity' => 7]);
        $this->product->defaultPackaging()->create(['name' => 'Doboz', 'pieces_per_package' => 10]);

        $operator = $this->member('supervisor', $this->warehouse);
        $this->actingAs($operator)->get(route('warehouse-relocation-pages.create'))->assertOk()->assertSeeText('Új belső áthelyezés');
        $this->getJson($this->url('sources', ['mode' => 'products']))->assertOk()->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.product_id', $this->product->getKey());
        $this->getJson($this->url('sources', ['product_id' => $this->product->getKey()]))->assertOk()->assertJsonCount(1, 'warehouses')
            ->assertJsonPath('warehouses.0.warehouse_id', $this->warehouse->getKey());
        $this->getJson($this->url('sources', $this->identity()))->assertOk()->assertJsonPath('packaging.pieces', 10)
            ->assertJsonPath('sources.0.movable_quantity', 40);
        $plan = route('warehouses.relocations.plan', [$this->warehouse, $this->to]);
        $command = $this->command(40);
        $this->postJson($this->url('candidates'), array_replace($command, ['mode' => 'cargo', 'to_location_id' => null]))->assertOk()
            ->assertJsonPath('cargo.cargo_weight_grams', 16000)->assertJsonMissingPath('recommendations');
        $this->postJson($this->url('candidates'), $command + ['mode' => 'search'])->assertOk()
            ->assertJsonPath('locations.0.floor_plan_url', $plan);
        $this->get($plan)->assertOk()->assertViewHas('plan', fn ($view) => !$view['canEdit'] && $view['focusBin'] === $this->to->id);
        $this->get(route('warehouse-relocation-pages.plan', [$this->warehouse, $remoteBin]))->assertNotFound();

        $id = $this->postJson($this->url('store'), $command)->assertCreated()->json('relocation.id');
        $this->get(route('warehouse-relocation-pages.index', ['search' => 'B-01', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 1]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->items()[0]->id === $id)
            ->assertSee('<span role="columnheader">Raktár</span>', false);
        $detail = route('warehouses.relocations.show', [$this->warehouse, $id]);
        $this->get(route('warehouse-relocation-pages.show', $id))->assertRedirect($detail);
        $this->get($detail)->assertOk()->assertSeeText('A-01')->assertSeeText('B-01')->assertSee($plan);
        $this->get(route('warehouse-relocation-pages.plan', [$this->warehouse, $this->to]))->assertOk();
        $this->get(route('warehouses.relocations.index', ['warehouse' => $this->warehouse, 'search' => 'B-01', 'per_page' => 1]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->items()[0]->id === $id)
            ->assertDontSee('<span role="columnheader">Raktár</span>', false);
        $this->actingAs($this->admin)->get(route('warehouses.relocations.show', [$remote, $id]))->assertNotFound();
        $this->actingAs($this->member('requester', $this->warehouse));
        $this->get(route('warehouse-relocation-pages.index'))->assertForbidden();
        $this->get(route('warehouse-relocation-pages.create'))->assertForbidden();
        $this->get(route('warehouse-relocation-pages.show', $id))->assertForbidden();
        $this->get($plan)->assertForbidden();
        $this->get($detail)->assertForbidden();
    }

    public function test_warehouse_entry_points_and_scoped_picker_allow_only_movable_products_from_the_selected_bin(): void
    {
        $this->place($this->from, 40, 12000);
        $warehouse = $this->warehouse;
        $create = route('warehouses.relocations.create', $warehouse);
        $history = route('warehouses.relocations.index', $warehouse);
        $source = route('warehouses.relocations.create', ['warehouse' => $warehouse, 'from_location_id' => $this->from->id]);
        $this->get(route('warehouses.show', $warehouse))->assertOk()->assertSee($create)->assertSee($history);
        $location = route('warehouses.locations.show', [$warehouse, $this->from]);
        $this->get($location)->assertOk()->assertSee($source)->assertSeeText('Áthelyezés innen');
        $this->get($create)->assertOk()->assertViewHas('warehouse', fn ($bound) => $bound->is($warehouse))
            ->assertSeeText('Célrekesz készlete')->assertSeeText('Raktári készletváltozás')
            ->assertSeeText('Rekeszkészlet:')->assertSeeText('Szint összesen:');
        $this->get(route('warehouse-relocation-pages.create', ['warehouse_id' => $warehouse->getKey(), 'from_location_id' => $this->from->id]))
            ->assertRedirect($source);
        $this->get($source)->assertOk()->assertViewHas('from', fn ($bound) => $bound->is($this->from))
            ->assertViewHas('initialProduct', fn ($product) => $product->product_id === $this->product->getKey());

        $other = Product::factory()->create();
        $otherStock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $other->getKey(), 'quantity' => 5]);
        $this->from->placements()->create(['stock_id' => $otherStock->id, 'quantity' => 5, 'cargo_weight_grams' => 1000]);
        $elsewhere = Product::factory()->create();
        $elsewhereStock = Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $elsewhere->getKey(), 'quantity' => 7]);
        $this->to->placements()->create(['stock_id' => $elsewhereStock->id, 'quantity' => 7, 'cargo_weight_grams' => 1400]);
        $this->get($source)->assertOk()->assertViewHas('initialProduct', null);
        $searchParams = ['warehouse_id' => $warehouse->getKey(), 'from_location_id' => $this->from->id, 'mode' => 'products'];
        $this->getJson($this->url('sources', $searchParams))->assertOk()->assertJsonPath('products.total', 2)
            ->assertJsonMissing(['product_id' => $elsewhere->getKey()]);
        $otherStock->update(['quarantined_quantity' => 5]);
        $this->getJson($this->url('sources', $searchParams))->assertOk()->assertJsonPath('products.total', 1);
        $this->getJson($this->url('sources', $searchParams + ['search' => $elsewhere->sku]))->assertOk()->assertJsonPath('products.total', 0);
        $this->getJson($this->url('sources', array_diff_key($searchParams, ['warehouse_id' => true])))->assertUnprocessable();

        $otherWarehouse = Warehouse::factory()->create();
        $otherBin = $this->bin('X-01', warehouse: $otherWarehouse);
        $this->get($create.'?from_location_id='.$otherBin->id)->assertNotFound();
        $this->get($create.'?warehouse_id='.$otherWarehouse->getKey())->assertNotFound();
        $this->getJson($this->url('sources', array_replace($searchParams, ['from_location_id' => $otherBin->id])))->assertNotFound();
        $inactive = $this->bin('KIKAPCSOLT');
        $inactive->update(['active' => false]);
        $this->get($create.'?from_location_id='.$inactive->id)->assertNotFound();
        $this->getJson($this->url('sources', array_replace($searchParams, ['from_location_id' => $inactive->id])))->assertNotFound();

        $operator = $this->member('dispatch_operator', $warehouse);
        $this->actingAs($operator)->get($location)->assertOk()->assertSee($source);
        $this->get($source)->assertOk();
        $this->get(route('warehouses.relocations.create', ['warehouse' => $otherWarehouse, 'from_location_id' => $otherBin->id]))->assertForbidden();
        $this->actingAs($this->member('requester', $warehouse))->get($location)->assertOk()->assertDontSee($source)->assertDontSee($history);
        $this->get($source)->assertForbidden();
        $this->get($history)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'manager']))->get(route('warehouses.show', $warehouse))
            ->assertOk()->assertDontSee($create)->assertDontSee($history);
        $this->get($create)->assertForbidden();
        $this->assertDatabaseCount('warehouse_relocations', 0);
    }

    public function test_a_changed_target_cannot_be_committed_with_a_stale_preview_even_when_the_load_still_fits(): void
    {
        $from = $this->place($this->from, 40, 12000);
        $command = $this->command(40);
        $preview = $this->postJson($this->url('preview'), $command)->assertOk()->assertJsonPath('can_relocate', true);
        $this->to->update(['max_weight_grams' => 20000]);
        $this->postJson($this->url('store'), $command + ['target_revision' => $preview->json('target_revision')])
            ->assertConflict()->assertJsonFragment(['message' => 'A célrekesz kapacitása közben megváltozott. Ellenőrizd újra az áthelyezést.']);
        $this->assertSame(40, $from->refresh()->quantity);
        $fresh = $this->postJson($this->url('preview'), $command)->assertOk()->json('target_revision');
        $this->postJson($this->url('store'), $command + ['target_revision' => $fresh])->assertCreated();
    }

    private function identity(): array
    {
        return ['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->product->getKey()];
    }

    private function command(int $quantity, ?WarehouseLocation $from = null, ?WarehouseLocation $to = null): array
    {
        return $this->identity() + [
            'from_location_id' => ($from ?? $this->from)->id, 'to_location_id' => ($to ?? $this->to)->id,
            'operation_key' => (string) Str::uuid(), 'confirmed' => true, 'quantity' => $quantity,
            'dimensions_cm' => ['width' => 25, 'depth' => 20, 'height' => 20],
        ];
    }

    private function url(string $name, array|int $parameters = []): string
    {
        return route('warehouse-relocations.'.$name, $parameters);
    }

    private function bin(string $code, ?Warehouse $warehouse = null): WarehouseLocation
    {
        $bin = ($warehouse ?? $this->warehouse)->storageLocations()->make();
        $bin->forceFill(['code' => $code, 'name' => $code, 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 100000,
            'width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        return $bin;
    }

    private function place(WarehouseLocation $bin, int $quantity, int $grams, int $slots = 0): WarehousePlacement
    {
        return $bin->placements()->create(['stock_id' => $this->stock->id, 'quantity' => $quantity,
            'cargo_weight_grams' => $grams, 'pallet_slots' => $slots, 'load_requires_verification' => false]);
    }

    private function rack(string $code = 'REK-A', int $x = 0, int $levels = 1, int $limit = 20): array
    {
        $result = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
            'version' => $this->warehouse->fresh()->floor_version, 'kind' => 'rack', 'code' => $code,
            'name' => 'Próbaállvány', 'zone' => 'A', 'width' => 2, 'height' => 2, 'rack_height' => 2, 'x' => $x, 'y' => 0,
            'levels' => $levels, 'bins' => 2, 'bin_weight' => $limit, 'level_weight' => $limit, 'system_weight' => $limit,
        ]);

        return $this->warehouse->storageSystems()->findOrFail($result['id'])->locations()->orderBy('id')->get()->all();
    }

    private function totals(): array
    {
        $stock = app(WarehouseStockAvailability::class)->snapshot($this->warehouse->getKey(), $this->product->getKey());
        $fields = ['physical_quantity', 'quarantined_quantity', 'staged_quantity', 'pending_putaway_quantity', 'source_reserved_quantity', 'pickable_quantity'];

        return array_replace(array_flip($fields), array_intersect_key($stock, array_flip($fields)));
    }

    private function member(string $role, Warehouse $warehouse): User
    {
        $actor = User::factory()->create(['role' => 'worker']);
        $actor->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $actor;
    }

    private function version(TransferIntent $intent): array
    {
        return ['version' => $intent->refresh()->version, 'operation_key' => (string) Str::uuid()];
    }
}
