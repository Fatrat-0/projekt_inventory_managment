<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, User, Warehouse, WarehouseLoadVerification, WarehouseLocation, WarehousePlacement};
use App\Queries\WarehouseStatistics;
use App\Services\{InventoryService, SmartPutaway, WarehouseCapacity, WarehouseStockAvailability, WarehouseStorageDesigner};
use App\Support\WarehouseStorageOverview;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseLoadVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;
    private Stock $stock;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::factory()->create();
        Category::factory()->create();
        $this->product = Product::factory()->create();
        $this->stock = Stock::create(['warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 30]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    public function test_relaxes_only_the_measured_placement_after_partial_relocation_and_keeps_immutable_history(): void
    {
        $source = $this->bin('A-01');
        $target = $this->bin('B-01');
        $first = $this->placement($source, 10, 10000, pending: false);
        $second = $this->placement($source, 2, 2000);
        $this->postJson(route('warehouse-relocations.store'), [
            'warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->product->getKey(),
            'from_location_id' => $source->id, 'to_location_id' => $target->id, 'quantity' => 4,
            'cargo_weight_kg' => 4, 'pallet_slots' => 0, 'site_verified' => true,
            'confirmed' => true, 'operation_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertSame([6, 10000, true], [$first->refresh()->quantity, $first->cargo_weight_grams, $first->load_requires_verification]);
        $before = $this->balances();
        $supervisor = $this->member('supervisor');
        $this->actingAs($supervisor);

        $this->getJson($this->url('index').'?per_page=1&location_id='.$source->id)
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('can_confirm', true)
            ->assertJsonPath('data.0.placement_id', $first->id)->assertJsonPath('data.0.quantity', 6)
            ->assertJsonPath('data.0.product.sku', $this->product->sku)->assertJsonPath('data.0.location.code', 'A-01')
            ->assertJsonPath('data.0.verification_reason', 'retained_load');
        $input = $this->input($first);
        $this->postJson($this->url('preview', $first), $input)
            ->assertOk()->assertJsonPath('can_verify', true)
            ->assertJsonPath('remaining_capacity.location_weight_grams', ['before' => 8000, 'after' => 12000]);
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
        $this->assertSame(10000, $first->fresh()->cargo_weight_grams);

        $saved = $this->postJson($this->url('store', $first), $input)->assertCreated()
            ->assertJsonPath('verification.actor_id', $supervisor->id)->assertJsonPath('verification.result', 'verified')
            ->assertJsonPath('verification.quantity', 6)->assertJsonPath('verification.previous_weight_grams', 10000)
            ->assertJsonPath('verification.measured_weight_grams', 6000)->assertJsonPath('verification.measured_pallet_slots', 0)
            ->assertJsonPath('verification.reason', 'Újramérés')->assertJsonPath('verification.note', 'Mérleggel ellenőrizve');
        $this->assertSame([6, 6000, false], [$first->refresh()->quantity, $first->cargo_weight_grams, $first->load_requires_verification]);
        $this->assertSame([2, 2000, true], [$second->refresh()->quantity, $second->cargo_weight_grams, $second->load_requires_verification]);
        $this->assertSame(12000, WarehouseLocation::withCapacityUsage()->findOrFail($source->id)->freeWeight());
        $this->assertSame($before, $this->balances());
        $this->assertSame(4, $target->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('warehouse_relocations', 1);
        $this->postJson($this->url('store', $first), array_replace($input, [
            'operation_key' => strtoupper($input['operation_key']), 'cargo_weight_kg' => '6.000',
        ]))->assertCreated()->assertExactJson($saved->json());
        $this->postJson($this->url('store', $first), array_replace($input, ['note' => 'Másik megjegyzés']))->assertConflict();
        $this->postJson($this->url('store', $first), array_replace($input, ['operation_key' => (string) Str::uuid()]))->assertConflict();
        $this->getJson($this->url('index'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.placement_id', $second->id);

        $source->update(['code' => 'A-ATNEVEZVE']);
        app(WarehouseCapacity::class)->release($source, $first->fresh());
        $this->getJson($this->url('history').'?placement_id='.$first->id)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.location_code', 'A-01')->assertJsonPath('data.0.placement_id', $first->id);
        $this->postJson($this->url('store', $first), $input)->assertCreated()->assertExactJson($saved->json());

        $audit = WarehouseLoadVerification::sole();
        $this->assertBlocked(fn () => $audit->update(['note' => 'Átírás']));
        $this->assertBlocked(fn () => $audit->delete());
        $this->assertDatabaseHas('warehouse_load_verifications', ['id' => $audit->id, 'note' => 'Mérleggel ellenőrizve']);
    }

    public function test_exact_overflow_and_available_capacity_are_distinct_from_prior_load(): void
    {
        $rack = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
            'version' => $this->warehouse->refresh()->floor_version, 'kind' => 'rack', 'name' => 'Állvány',
            'zone' => 'A', 'code' => 'A01', 'width' => 4, 'height' => 2, 'rack_height' => 2, 'x' => 0, 'y' => 0,
            'levels' => 1, 'bins' => 2, 'bin_weight' => 25, 'level_weight' => 100, 'system_weight' => 120,
        ]);
        [$bin, $adjacent] = $this->warehouse->storageSystems()->findOrFail($rack['id'])->locations()->orderBy('code')->get()->all();
        $row = $this->placement($bin, 10, 10000);
        $this->placement($adjacent, 4, 16000, pending: false);
        $input = $this->input($row, 35) + ['measurement_confirmed' => true];
        $this->postJson($this->url('preview', $row), $input)->assertOk()
            ->assertJsonPath('severity', 'critical')->assertJsonPath('checks.location_weight.passed', false)
            ->assertJsonPath('checks.location_weight.message', 'Rekesz teherbírása túllépve. Túllépés: 10 kg.')
            ->assertJsonPath('checks.location_weight.capacity', ['scope' => 'location_grams', 'unit' => 'grams',
                'limit' => 25000, 'projected' => 35000, 'excess' => 10000])
            ->assertJsonPath('remaining_capacity.location_weight_grams', ['before' => 15000, 'after' => -10000])
            ->assertJsonPath('capacity_comparison.location_weight_grams', [
                'limit' => 25000, 'unit' => 'grams',
                'before' => ['committed' => 10000, 'remaining' => 15000, 'excess' => 0, 'usable' => 15000, 'block' => null],
                'after' => ['committed' => 35000, 'remaining' => -10000, 'excess' => 10000, 'usable' => 0, 'block' => 'load_discrepancy'],
            ])
            ->assertJsonPath('capacity_comparison.level_weight_grams.after',
                ['committed' => 51000, 'remaining' => 49000, 'excess' => 0, 'usable' => 0, 'block' => 'load_discrepancy'])
            ->assertJsonPath('checks.level_weight.passed', true)->assertJsonPath('checks.system_weight.passed', true);
        $this->postJson($this->url('store', $row), $input)->assertUnprocessable()->assertJsonPath('discrepancy_open', true);
        $this->getJson($this->url('show', $row))->assertOk()
            ->assertJsonPath('placement.severity', 'critical')->assertJsonPath('placement.discrepancy.limits.location_weight.excess', 10000)
            ->assertJsonPath('capacity.scopes.level_grams', ['limit' => 100000, 'committed' => 51000, 'remaining' => 49000, 'free' => 0, 'excess' => 0]);
        $this->getJson($this->url('index'))->assertOk()->assertJsonPath('data.0.discrepancy.limits.location_weight.excess', 10000);
        $this->postJson($this->url('preview', $row), $this->input($row, 35))->assertOk()
            ->assertJsonPath('capacity_comparison.location_weight_grams.before.excess', 10000)
            ->assertJsonPath('capacity_comparison.location_weight_grams.after.excess', 10000)
            ->assertJsonPath('capacity_comparison.level_weight_grams.before',
                ['committed' => 51000, 'remaining' => 49000, 'excess' => 0, 'usable' => 0, 'block' => 'load_discrepancy']);
        $this->postJson($this->url('preview', $row), $this->input($row, 20))->assertOk()->assertJsonPath('can_verify', true)
            ->assertJsonPath('capacity_comparison.level_weight_grams.after',
                ['committed' => 36000, 'remaining' => 64000, 'excess' => 0, 'usable' => 64000, 'block' => null]);
        $this->assertSame('critical', $this->rejected($bin)['discrepancy_severity']);
        $this->assertSame(10000, $row->fresh()->cargo_weight_grams);
        $this->assertSame(10, $row->fresh()->quantity);

        $normal = $this->placement($this->bin('NORM-01', ['max_weight_grams' => 25000]), 4, 5000);
        $this->postJson($this->url('preview', $normal), $this->input($normal, 3))->assertOk()
            ->assertJsonPath('severity', 'success')->assertJsonPath('checks.location_weight.passed', true)
            ->assertJsonPath('checks.location_weight.capacity.excess', 0)
            ->assertJsonPath('remaining_capacity.location_weight_grams', ['before' => 20000, 'after' => 22000])
            ->assertJsonPath('capacity_comparison.location_weight_grams.after',
                ['committed' => 3000, 'remaining' => 22000, 'excess' => 0, 'usable' => 22000, 'block' => null]);
    }

    public function test_zero_pallet_slots_is_explicit_and_only_allowed_for_a_compatible_bin(): void
    {
        $floor = $this->bin('PAD', ['kind' => 'floor', 'max_pallet_slots' => 3]);
        $row = $this->placement($floor, 6, 8000, 1);
        $input = $this->input($row);
        $this->postJson($this->url('preview', $row), array_diff_key($input, ['pallet_slots' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->postJson($this->url('store', $row), array_replace($input, ['pallet_slots' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->postJson($this->url('store', $row), $input + ['quantity' => 7])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
        $this->postJson($this->url('preview', $row), $input)->assertOk()->assertJsonPath('can_verify', true)
            ->assertJsonPath('checks.pallet_space.passed', true)->assertJsonPath('checks.pallet_space.message', null)
            ->assertJsonPath('remaining_capacity.pallet_slots', ['before' => 2, 'after' => 3]);
        $this->postJson($this->url('store', $row), $input)->assertCreated()->assertJsonPath('verification.measured_pallet_slots', 0);
        $this->assertSame(0, $row->fresh()->pallet_slots);

        $pallet = $this->placement($this->bin('RAK', ['kind' => 'pallet', 'max_pallet_slots' => 2]), 6, 8000, 1);
        $this->postJson($this->url('store', $pallet), $this->input($pallet) + ['measurement_confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('pallet_slots')->assertJsonPath('verification.result', 'rejected');
        $this->assertTrue($pallet->fresh()->load_requires_verification);
    }

    public function test_rejects_measured_overload_at_bin_level_and_rack_including_existing_reservations(): void
    {
        $rack = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
            'version' => $this->warehouse->refresh()->floor_version, 'kind' => 'rack', 'name' => 'Állvány',
            'zone' => 'A', 'code' => 'A01', 'width' => 4, 'height' => 2, 'rack_height' => 2, 'x' => 0, 'y' => 0,
            'levels' => 2, 'bins' => 2, 'bin_weight' => 15, 'level_weight' => 16, 'system_weight' => 20,
        ]);
        [$bin, $neighbour, $otherLevel, $reserved] = $this->warehouse->storageSystems()->findOrFail($rack['id'])
            ->locations()->orderBy('code')->get()->all();
        $row = $this->placement($bin, 6, 6000);
        $this->placement($neighbour, 4, 4000, pending: false);
        $this->placement($otherLevel, 5, 5000, pending: false);
        $origin = Warehouse::factory()->create();
        Stock::create(['warehouse_id' => $origin->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 2]);
        $transfer = app(InventoryService::class)->startTransfer([
            'from_warehouse_id' => $origin->getKey(), 'to_warehouse_id' => $this->warehouse->getKey(),
            'product_id' => $this->product->getKey(), 'quantity' => 1, 'user_id' => $this->admin->id,
            'to_location_id' => $reserved->id, 'cargo_weight_kg' => 3,
        ]);
        $before = $this->balances();
        $input = $this->input($row, 16) + ['measurement_confirmed' => true];
        $check = $this->postJson($this->url('preview', $row), $input)->assertOk()->assertJsonPath('can_verify', false)
            ->assertJsonPath('checks.location_weight.passed', false)->assertJsonPath('checks.level_weight.passed', false)
            ->assertJsonPath('checks.location_weight.capacity.excess', 1000)->assertJsonPath('checks.level_weight.capacity.excess', 4000)
            ->assertJsonPath('checks.system_weight.capacity.excess', 8000)
            ->assertJsonPath('checks.system_weight.passed', false)->assertJsonPath('remaining_capacity.system_weight_grams', ['before' => 2000, 'after' => -8000]);
        $this->assertCount(3, $check->json('errors.cargo_weight_kg'));
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
        $failure = $this->postJson($this->url('store', $row), $input)
            ->assertUnprocessable()->assertJsonPath('verification.result', 'rejected')
            ->assertJsonPath('verification.measured_weight_grams', 16000);
        $this->postJson($this->url('store', $row), $input)->assertUnprocessable()->assertExactJson($failure->json());
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.last_rejection.measured_weight_grams', 16000);
        $this->getJson($this->url('index'))->assertOk()->assertJsonPath('data.0.last_rejection.result', 'rejected');
        $this->assertTrue($row->refresh()->load_requires_verification);
        $this->assertSame(6000, $row->cargo_weight_grams);
        $this->assertSame($before, $this->balances());
        $this->assertDatabaseHas('inventory_transfers', ['id' => $transfer->id, 'status' => 'pending', 'cargo_weight_grams' => 3000]);
        $this->assertDatabaseCount('warehouse_load_verifications', 1);
        $this->postJson($this->url('store', $row), $this->input($row, 8))->assertCreated();
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.last_rejection', null);
        $this->assertSame(8000, $row->fresh()->cargo_weight_grams);
    }

    public function test_old_revision_cannot_replace_a_later_partial_move_or_a_removed_placement(): void
    {
        $source = $this->bin('A');
        $target = $this->bin('B');
        $row = $this->placement($source, 10, 10000);
        $stale = $this->input($row);
        $move = [
            'warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->product->getKey(),
            'from_location_id' => $source->id, 'to_location_id' => $target->id, 'cargo_weight_kg' => 4,
            'pallet_slots' => 0, 'source_load_verified' => true, 'site_verified' => true, 'confirmed' => true,
        ];
        $this->postJson(route('warehouse-relocations.store'), $move + ['quantity' => 4, 'operation_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($this->url('preview', $row), $stale)->assertConflict();
        $this->postJson($this->url('store', $row), $stale)->assertConflict();
        $this->assertSame([6, 10000, true], [$row->refresh()->quantity, $row->cargo_weight_grams, $row->load_requires_verification]);
        $remaining = $this->input($row);
        $this->postJson(route('warehouse-relocations.store'), $move + ['quantity' => 6, 'operation_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($this->url('store', $row), $remaining)->assertConflict();
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
    }

    public function test_roles_and_warehouse_scope_apply_to_reads_preview_confirmation_and_history(): void
    {
        $row = $this->placement($this->bin('A'), 6, 10000);
        $input = $this->input($row);
        foreach (['dispatch_operator', 'receiving_operator'] as $role) {
            $this->actingAs($this->member($role));
            $this->getJson($this->url('index'))->assertOk()->assertJsonPath('can_confirm', false);
            $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('can_confirm', false);
            $this->getJson($this->url('history'))->assertOk();
            $this->postJson($this->url('preview', $row), $input)->assertOk()->assertJsonPath('can_confirm', false);
            $this->postJson($this->url('store', $row), $input)->assertForbidden();
        }

        $foreign = Warehouse::factory()->create();
        foreach ([$this->member('requester'), $this->member('supervisor', $foreign)] as $actor) {
            $this->actingAs($actor);
            $this->getJson($this->url('index'))->assertForbidden();
            $this->getJson($this->url('show', $row))->assertForbidden();
            $this->getJson($this->url('history'))->assertForbidden();
            $this->postJson($this->url('preview', $row), $input)->assertForbidden();
            $this->postJson($this->url('store', $row), $input)->assertForbidden();
        }
        $this->actingAs($this->admin);
        $this->getJson($this->url('show', $row, $foreign))->assertNotFound();
        $this->postJson($this->url('preview', $row, $foreign), $input)->assertNotFound();
        $this->postJson($this->url('store', $row, $foreign), $input)->assertConflict();
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
    }

    public function test_late_database_audit_failure_rolls_back_the_load_and_allows_the_same_uuid_to_retry(): void
    {
        $row = $this->placement($this->bin('A'), 6, 10000);
        $input = $this->input($row);
        $before = $this->balances();
        $this->withoutExceptionHandling();
        DB::unprepared("CREATE TRIGGER reject_load_test BEFORE INSERT ON warehouse_load_verifications
            BEGIN SELECT RAISE(ABORT, 'Késői teszthiba'); END");
        try {
            $this->assertBlocked(fn () => $this->postJson($this->url('store', $row), $input), 'Késői teszthiba');
            $this->assertSame([6, 10000, true], [$row->refresh()->quantity, $row->cargo_weight_grams, $row->load_requires_verification]);
            $this->assertDatabaseCount('warehouse_load_verifications', 0);
            $this->assertSame($before, $this->balances());
        } finally {
            DB::unprepared('DROP TRIGGER reject_load_test');
        }
        $this->postJson($this->url('store', $row), $input)->assertCreated();
        $this->assertDatabaseCount('warehouse_load_verifications', 1);
    }

    public function test_confirmed_overload_uses_the_measured_load_and_blocks_new_placement_reservation_and_legacy_receipt(): void
    {
        $bin = $this->bin('MAX-12', ['max_weight_grams' => 12000]);
        $row = $this->placement($bin, 10, 10000);
        $this->assertSame(2000, WarehouseLocation::withCapacityUsage()->findOrFail($bin->id)->freeWeight());
        $origin = Warehouse::factory()->create();
        Stock::create(['warehouse_id' => $origin->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 10]);
        $transfer = ['from_warehouse_id' => $origin->getKey(), 'to_warehouse_id' => $this->warehouse->getKey(),
            'product_id' => $this->product->getKey(), 'quantity' => 1, 'user_id' => $this->admin->id,
            'to_location_id' => $bin->id, 'cargo_weight_kg' => 1];
        $reserved = app(InventoryService::class)->startTransfer($transfer);
        $input = $this->input($row, 14);
        $this->postJson($this->url('preview', $row), $input)->assertOk()->assertJsonPath('requires_physical_confirmation', true);
        foreach ([[], ['measurement_confirmed' => false]] as $unconfirmed) {
            $this->postJson($this->url('store', $row), $input + $unconfirmed)
                ->assertUnprocessable()->assertJsonValidationErrors('measurement_confirmed');
        }
        $this->postJson($this->url('store', $row), array_replace($input, ['cargo_weight_kg' => -14, 'measurement_confirmed' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->assertDatabaseCount('warehouse_load_verifications', 0);
        $input['measurement_confirmed'] = true;
        $failed = $this->postJson($this->url('store', $row), $input)->assertUnprocessable()->assertJsonPath('discrepancy_open', true)
            ->assertJsonPath('verification.capacity_violations', ['location_weight']);
        $this->postJson($this->url('store', $row), $input)->assertUnprocessable()->assertExactJson($failed->json());
        $this->postJson($this->url('store', $row), array_replace($input, ['operation_key' => (string) Str::uuid()]))->assertConflict();
        $state = $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.status', 'discrepancy_open')
            ->assertJsonPath('placement.recorded_weight_grams', 10000)->assertJsonPath('placement.discrepancy.measurement_current', true)
            ->assertJsonPath('placement.discrepancy.measurement.measured_weight_grams', 14000)
            ->assertJsonPath('placement.discrepancy.measurement.actor_id', $this->admin->id);
        $this->assertNotEmpty($state->json('placement.discrepancy.measurement.measured_at'));
        $usage = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame(14000, $usage->effectiveOccupiedWeight());
        $this->assertSame(0, WarehouseStorageOverview::locationAvailability($usage->newCollection([$usage]))[$bin->id]['weight']);
        $this->assertSame(0, WarehouseStatistics::reportRows($this->warehouse->getKey())->sole()->usable_free_weight);
        $this->assertSame(0, $this->rejected($bin)['free_weight_grams']);
        $this->postJson(route('warehouses.locations.place', [$this->warehouse, $bin]), $this->placementCommand())
            ->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $this->assertCapacityBlocked(fn () => app(InventoryService::class)->startTransfer($transfer));
        $this->assertCapacityBlocked(fn () => app(InventoryService::class)->finalizeTransfer($reserved, true));
        $this->assertSame('pending', $reserved->fresh()->status);
        $this->assertSame(30, $this->stock->fresh()->quantity);
        $this->assertSame(10, $row->fresh()->quantity);
        $this->assertDatabaseCount('warehouse_load_verifications', 1);
    }

    public function test_affected_level_and_rack_block_other_bins_without_blocking_independent_limits(): void
    {
        foreach (['level_weight' => [12, 40], 'system_weight' => [25, 12]] as $rule => [$level, $system]) {
            $rack = app(WarehouseStorageDesigner::class)->create($this->warehouse, [
                'version' => $this->warehouse->refresh()->floor_version, 'kind' => 'rack', 'name' => 'Állvány', 'zone' => 'A',
                'code' => 'TEST-'.$level, 'width' => 2, 'height' => 2, 'rack_height' => 2, 'x' => $level === 12 ? 0 : 3, 'y' => 0,
                'levels' => 2, 'bins' => 2, 'bin_weight' => 20, 'level_weight' => $level, 'system_weight' => $system,
            ]);
            [$bin, $sameLevel, $otherLevel] = $this->warehouse->storageSystems()->findOrFail($rack['id'])
                ->locations()->orderBy('code')->get()->all();
            $row = $this->placement($bin, 10, 10000);
            $open = $this->postJson($this->url('store', $row), $this->input($row, 14) + ['measurement_confirmed' => true])
                ->assertUnprocessable()->assertJsonPath('verification.capacity_violations', [$rule])->json('verification.id');
            $blocked = $rule === 'level_weight' ? $sameLevel : $otherLevel;
            $reason = $rule === 'level_weight' ? 'level_discrepancy' : 'system_discrepancy';
            $this->assertArrayHasKey($reason, $this->rejected($blocked)['checks']['failed']);
            $this->postJson(route('warehouses.locations.place', [$this->warehouse, $blocked]), $this->placementCommand())
                ->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
            if ($rule === 'level_weight') {
                $this->post(route('warehouses.locations.place', [$this->warehouse, $otherLevel]), $this->placementCommand())
                    ->assertRedirect()->assertSessionHasNoErrors();
            }
            $accepted = $this->postJson($this->url('store', $row), $this->input($row, 8))->assertCreated()
                ->assertJsonPath('verification.resolves_verification_id', $open);
            $this->getJson($this->url('history').'?placement_id='.$row->id)->assertOk()
                ->assertJsonPath('data.1.discrepancy_open', false)->assertJsonPath('data.1.resolution.method', 'remeasured')
                ->assertJsonPath('data.1.resolution.id', $accepted->json('verification.id'))
                ->assertJsonPath('data.1.resolution.measured_weight_grams', 8000);
        }
    }

    public function test_pallet_space_is_independent_and_two_open_rows_are_resolved_separately(): void
    {
        $bin = $this->bin('RAK-SAFE', ['kind' => 'pallet', 'max_pallet_slots' => 3]);
        $first = $this->placement($bin, 10, 5000, 1);
        $second = $this->placement($bin, 10, 5000, 1);
        $this->postJson($this->url('store', $first), $this->input($first, 5) + ['measurement_confirmed' => true])
            ->assertUnprocessable()->assertJsonPath('verification.capacity_violations', ['pallet_kind']);
        $command = array_replace($this->input($second, 3), ['pallet_slots' => 3, 'measurement_confirmed' => true]);
        $this->postJson($this->url('preview', $second), $command)->assertOk()
            ->assertJsonPath('severity', 'critical')->assertJsonPath('checks.pallet_space.passed', false)
            ->assertJsonPath('checks.pallet_space.capacity', ['scope' => 'slots', 'unit' => 'pallet_slots', 'limit' => 3, 'projected' => 4, 'excess' => 1])
            ->assertJsonPath('checks.location_weight.passed', true)
            ->assertJsonPath('checks.location_weight.capacity.projected', 8000)
            ->assertJsonPath('capacity_comparison.location_weight_grams.after.committed', 10000)
            ->assertJsonPath('capacity_comparison.pallet_slots', [
                'limit' => 3, 'unit' => 'pallet_slots',
                'before' => ['committed' => 2, 'remaining' => 1, 'excess' => 0, 'usable' => 0, 'block' => 'load_discrepancy'],
                'after' => ['committed' => 4, 'remaining' => -1, 'excess' => 1, 'usable' => 0, 'block' => 'load_discrepancy'],
            ]);
        $this->postJson($this->url('store', $second), $command)
            ->assertUnprocessable()->assertJsonPath('verification.capacity_violations', ['pallet_space']);
        $this->assertSame(4, WarehouseLocation::withCapacityUsage()->findOrFail($bin->id)->effectiveOccupiedSlots());
        $this->assertSame(0, $this->rejected($bin, slots: 1)['free_pallet_slots']);
        $resolving = array_replace($this->input($second, 5), ['pallet_slots' => 1]);
        $this->postJson($this->url('preview', $second), $resolving)->assertOk()->assertJsonPath('can_verify', true)
            ->assertJsonPath('capacity_comparison.pallet_slots.after',
                ['committed' => 2, 'remaining' => 1, 'excess' => 0, 'usable' => 0, 'block' => 'load_discrepancy']);
        $this->postJson($this->url('store', $second), $resolving)->assertCreated();
        $this->getJson($this->url('index'))->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.placement_id', $first->id)->assertJsonPath('data.0.status', 'discrepancy_open');
        $this->assertSame(0, $this->rejected($bin, slots: 1)['free_pallet_slots']);
    }

    public function test_direct_bin_limits_cannot_be_reduced_below_a_documented_open_load(): void
    {
        $bin = $this->bin('PAL-OPEN', ['kind' => 'pallet', 'max_weight_grams' => 25000, 'max_pallet_slots' => 3]);
        $row = $this->placement($bin, 6, 10000, 1);
        $reading = array_replace($this->input($row, 15), ['pallet_slots' => 4, 'measurement_confirmed' => true]);
        $this->postJson($this->url('store', $row), $reading)->assertUnprocessable()
            ->assertJsonPath('verification.capacity_violations', ['pallet_space']);

        $url = route('warehouses.locations.update', [$this->warehouse, $bin]);
        $settings = ['code' => $bin->code, 'name' => $bin->name, 'kind' => 'pallet', 'active' => true,
            'max_weight_kg' => 25, 'max_pallet_slots' => 3, 'lock_version' => $bin->refresh()->lock_version];
        $this->patchJson($url, array_replace($settings, ['max_weight_kg' => 12]))
            ->assertUnprocessable()->assertJsonValidationErrors('max_weight_kg');
        $this->patchJson($url, array_replace($settings, ['max_pallet_slots' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('max_pallet_slots');
        $this->assertSame([25000, 3], [$bin->refresh()->max_weight_grams, $bin->max_pallet_slots]);

        $this->patchJson($url, array_replace($settings, ['max_pallet_slots' => 4]))->assertRedirect();
        $this->assertSame(4, $bin->refresh()->max_pallet_slots);
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.status', 'discrepancy_open')
            ->assertJsonPath('capacity.scopes.slots.free', 0);
    }

    public function test_partial_issue_and_full_relocation_keep_old_measurement_open_until_verified_empty(): void
    {
        $bin = $this->bin('RELIEF', ['max_weight_grams' => 12000]);
        $target = $this->bin('SAFE');
        $row = $this->placement($bin, 10, 10000);
        $this->postJson($this->url('store', $row), $this->input($row, 14) + ['measurement_confirmed' => true])->assertUnprocessable();
        $stale = $this->input($row, 11);
        $this->postJson(route('movements.store'), [
            'type' => 'out', 'product_id' => $this->product->getKey(), 'warehouse_id' => $this->warehouse->getKey(),
            'quantity' => 4, 'confirmed' => true, 'request_key' => (string) Str::uuid(),
            'source_allocations' => [['source_type' => 'location', 'location_id' => $bin->id, 'quantity' => 4]],
        ])->assertOk();
        $this->postJson($this->url('store', $row), $stale)->assertConflict();
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.quantity', 6)
            ->assertJsonPath('placement.discrepancy.measurement.quantity', 10)
            ->assertJsonPath('placement.severity', 'warning')
            ->assertJsonPath('placement.discrepancy.limits', [])
            ->assertJsonPath('placement.discrepancy.measurement_current', false);
        $this->assertSame('warning', $this->rejected($bin)['discrepancy_severity']);
        $this->assertSame(0, $this->rejected($bin)['free_weight_grams']);
        $this->postJson(route('warehouse-relocations.store'), [
            'warehouse_id' => $this->warehouse->getKey(), 'product_id' => $this->product->getKey(),
            'from_location_id' => $bin->id, 'to_location_id' => $target->id, 'quantity' => 6,
            'cargo_weight_kg' => 8, 'pallet_slots' => 0, 'source_load_verified' => true, 'site_verified' => true,
            'confirmed' => true, 'operation_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertDatabaseMissing('warehouse_placements', ['id' => $row->id]);
        $this->assertSame(8000, $target->placements()->sole()->cargo_weight_grams);
        $this->assertSame(26, $this->stock->fresh()->quantity);
        $this->getJson($this->url('index').'?product_id='.$this->product->getKey().'&location_id='.$bin->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.placement_present', false)
            ->assertJsonPath('data.0.discrepancy.next_action', 'confirm_empty');
        $this->assertSame(0, $this->rejected($bin)['free_weight_grams']);
        $empty = $this->input($row, 0);
        $this->postJson($this->url('store', $row), $empty)->assertUnprocessable()->assertJsonValidationErrors('measurement_confirmed');
        $this->postJson($this->url('store', $row), $empty + ['measurement_confirmed' => true])->assertCreated()
            ->assertJsonPath('verification.quantity', 0);
        $this->getJson($this->url('index'))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url('history').'?placement_id='.$row->id)->assertOk()->assertJsonPath('data.1.resolution.method', 'confirmed_empty');
        $this->assertSame(12000, WarehouseLocation::withCapacityUsage()->findOrFail($bin->id)->freeWeight());
    }

    public function test_legacy_rejected_audit_opens_a_block_and_a_direct_placement_edit_does_not_clear_it(): void
    {
        $bin = $this->bin('LEGACY', ['max_weight_grams' => 12000]);
        $row = $this->placement($bin, 10, 10000);
        $input = $this->input($row, 14);
        $errors = ['cargo_weight_kg' => ['LEGACY tárhelyen 12 kg szabad teherbírás maradt.']];
        $audit = WarehouseLoadVerification::create([
            'warehouse_id' => $this->warehouse->getKey(), 'warehouse_name' => $this->warehouse->warehouse_name,
            'location_id' => $bin->id, 'location_code' => $bin->code, 'location_name' => $bin->name,
            'placement_id' => $row->id, 'quantity' => 10, 'product_id' => $this->product->getKey(),
            'product_name' => $this->product->product_name, 'sku' => $this->product->sku,
            'previous_weight_grams' => 10000, 'measured_weight_grams' => 14000, 'previous_pallet_slots' => 0,
            'measured_pallet_slots' => 0, 'placement_revision' => $input['revision'], 'result' => 'rejected',
            'errors' => $errors, 'actor_id' => $this->admin->id, 'actor_name' => $this->admin->name,
            'measured_at' => now(), 'operation_key' => $input['operation_key'], 'request_hash' => str_repeat('a', 64),
        ]);
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.status', 'discrepancy_open')
            ->assertJsonPath('placement.discrepancy.violations', ['location_weight']);
        $this->assertSame(0, $this->rejected($bin)['free_weight_grams']);
        $row->update(['load_requires_verification' => false]);
        $bin->update(['max_weight_grams' => 25000]);
        $this->getJson($this->url('show', $row))->assertOk()->assertJsonPath('placement.discrepancy.measurement_current', false);
        $this->assertSame(0, $this->rejected($bin)['free_weight_grams']);
        $this->assertBlocked(fn () => $audit->update(['result' => 'verified']));
        $this->postJson($this->url('store', $row), $this->input($row, 14))->assertCreated()
            ->assertJsonPath('verification.resolves_verification_id', $audit->id);
    }

    private function rejected(WarehouseLocation $bin, int $slots = 0): array
    {
        $result = app(SmartPutaway::class)->recommend($this->warehouse, $this->product,
            ['quantity' => 1, 'pallet_slots' => $slots, 'cargo_weight_kg' => 1]);
        $this->assertNotContains($bin->id, array_column($result['recommendations'], 'location_id'));

        return collect($result['rejected'])->firstWhere('location_id', $bin->id);
    }

    private function placementCommand(): array
    {
        return ['product_id' => $this->product->getKey(), 'quantity' => 1, 'cargo_weight_kg' => 1, 'request_key' => (string) Str::uuid()];
    }

    private function assertCapacityBlocked(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A nyitott eltérésnek blokkolnia kellett volna az újabb terhelést.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertStringContainsString('nyitott terhelési eltérés', $error->errors()['to_location_id'][0]);
        }
    }

    private function bin(string $code, array $attributes = []): WarehouseLocation
    {
        return $this->warehouse->storageLocations()->create($attributes + ['code' => $code, 'name' => $code,
            'kind' => 'shelf', 'max_weight_grams' => 20000, 'active' => true]);
    }

    private function placement(WarehouseLocation $bin, int $quantity, int $grams, int $slots = 0, bool $pending = true): WarehousePlacement
    {
        return $bin->placements()->create(['stock_id' => $this->stock->id, 'quantity' => $quantity,
            'cargo_weight_grams' => $grams, 'pallet_slots' => $slots, 'load_requires_verification' => $pending]);
    }

    private function url(string $action, ?WarehousePlacement $row = null, ?Warehouse $warehouse = null): string
    {
        return route('warehouse-load-verifications.'.$action, array_filter(['warehouse' => $warehouse ?? $this->warehouse, 'placement' => $row?->id]));
    }

    private function input(WarehousePlacement $row, int $weight = 6): array
    {
        $revision = $this->getJson($this->url('show', $row))->assertOk()->json('placement.revision');

        return ['revision' => $revision, 'cargo_weight_kg' => $weight, 'pallet_slots' => 0, 'operation_key' => (string) Str::uuid(),
            'reason' => 'Újramérés', 'note' => 'Mérleggel ellenőrizve'];
    }

    private function balances(): array
    {
        return app(WarehouseStockAvailability::class)->snapshot($this->warehouse->getKey(), $this->product->getKey());
    }

    private function member(string $role, ?Warehouse $warehouse = null): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->warehouseMemberships()->create(['warehouse_id' => ($warehouse ?? $this->warehouse)->getKey(), 'role' => $role]);

        return $user;
    }

    private function assertBlocked(callable $operation, string $message = 'utólag módosított terhelésellenőrzési'): void
    {
        try {
            $operation();
            $this->fail('A műveletnek adatbázishibával kellett volna meghiúsulnia.');
        } catch (QueryException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }
}
