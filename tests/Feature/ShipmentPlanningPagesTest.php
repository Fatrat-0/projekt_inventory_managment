<?php

namespace Tests\Feature;

use App\Models\{Shipment, User, Warehouse};
use App\Services\ShipmentPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentPlanningPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23T14:23:37Z'));
    }

    public function test_list_and_create_pages_scope_warehouse_and_shipment_access_per_policy(): void
    {
        $source = Warehouse::create(['warehouse_name' => 'Forrásraktár']);
        $other = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        $admin = User::factory()->create(['role' => 'admin']);
        $supervisor = $this->warehouseMember($source, 'supervisor');
        $dispatcher = $this->warehouseMember($source, 'dispatch_operator');
        $requester = $this->warehouseMember($source, 'requester');
        $receiver = $this->warehouseMember($other, 'receiving_operator');
        $first = $this->plan($admin, $source, 'Helyi közös fuvar');
        $second = $this->plan($admin, $other, 'Távoli közös fuvar');

        $this->actingAs($supervisor)->get(route('shipments.index'))->assertOk()
            ->assertSee('Új fuvar tervezése')->assertSee($first->reference)->assertDontSee($second->reference);
        $this->get(route('shipments.create'))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->modelKeys() === [$source->getKey()]);
        $this->get(route('shipments.show', $first))->assertOk()
            ->assertViewHas('canManage', true)->assertViewHas('canOverrideDedicated', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('shipments.show', $second))->assertForbidden();

        $this->actingAs($dispatcher)->get(route('shipments.index'))->assertOk()
            ->assertSee($first->reference)->assertDontSee('Új fuvar tervezése')->assertDontSee($second->reference);
        $this->get(route('shipments.create'))->assertForbidden();
        $this->get(route('shipments.show', $first))->assertOk()->assertViewHas('canManage', false)->assertViewHas('canOverrideDedicated', false);
        $this->get(route('shipments.show', $second))->assertForbidden();

        foreach ([$requester, $receiver] as $actor) {
            $this->actingAs($actor)->get(route('shipments.index'))->assertForbidden();
            $this->get(route('shipments.create'))->assertForbidden();
            $this->get(route('shipments.show', $first))->assertForbidden();
            $this->get(route('transfer-intent-pages.index'))->assertOk()->assertDontSee(route('shipments.index'));
        }
    }

    public function test_list_and_json_use_the_same_validated_search_order_and_pagination(): void
    {
        $source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $other = Warehouse::create(['warehouse_name' => 'Másik forrás']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $b = $this->plan($admin, $source, 'Minta-100% B');
        $a = $this->plan($admin, $source, 'Minta-100% A');
        $this->plan($admin, $source, 'Minta-1000');
        $this->plan($admin, $other, 'Minta-100% C');

        $filters = ['search' => 'Minta-100%', 'origin_warehouse_id' => $source->getKey(), 'sort_by' => 'reference', 'sort_dir' => 'asc', 'per_page' => 1];
        $this->get(route('shipments.index', $filters))->assertOk()->assertSee($a->reference)->assertDontSee($b->reference)
            ->assertViewHas('plans', fn ($plans) => $plans->total() === 2 && $plans->items()[0]['id'] === $a->id);
        $this->getJson(route('shipment-plans.index', $filters + ['page' => 2]))->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $b->id)->assertJsonPath('data.0.assignment_count', 0);
    }

    public function test_single_sorting_handles_warehouse_names_missing_dates_and_stable_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $alpha = Warehouse::create(['warehouse_name' => 'Alpha']);
        $zulu = Warehouse::create(['warehouse_name' => 'Zulu']);
        $first = $this->plan($admin, $zulu, 'Rendezés Z', ['planned_departure_at' => '2030-01-01T10:00:00Z']);
        $second = $this->plan($admin, $alpha, 'Rendezés A', ['planned_departure_at' => '2029-01-01T10:00:00Z']);
        $unknown = $this->plan($admin, $alpha, 'Rendezés M');
        $this->actingAs($admin);
        foreach (['asc' => [$second->id, $first->id, $unknown->id], 'desc' => [$first->id, $second->id, $unknown->id]] as $direction => $ids) {
            $filters = ['search' => 'Rendezés', 'sort_by' => 'planned_departure_at', 'sort_dir' => $direction];
            $this->get(route('shipments.index', $filters))->assertOk()
                ->assertViewHas('plans', fn ($rows) => array_column($rows->items(), 'id') === $ids);
            $this->getJson(route('shipment-plans.index', $filters))->assertOk()
                ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.2.id', $unknown->id);
        }
        $this->get(route('shipments.index', ['sort_by' => 'origin_warehouse', 'sort_dir' => 'asc', 'per_page' => 2]))
            ->assertOk()->assertViewHas('plans', fn ($rows) => array_column($rows->items(), 'id') === [$second->id, $unknown->id])
            ->assertSee('id="shipment-filters"', false)
            ->assertSee('href="'.route('shipments.show', $second).'"', false)
            ->assertDontSee('for="plan-sort"', false)->assertDontSee('Megnyitás ·');
        $this->getJson(route('shipment-plans.index', ['sort_by' => 'origin_warehouse', 'sort_dir' => 'asc', 'per_page' => 2, 'page' => 2]))
            ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('total', 3);
        $this->getJson(route('shipment-plans.index', ['sort_by' => 'shipments.id; DROP TABLE shipments']))
            ->assertUnprocessable()->assertJsonValidationErrors('sort_by');
        $this->get(route('shipments.index', ['search' => 'Rendezés A', 'sort_by' => ['id']]))
            ->assertOk()->assertSee('Válassz érvényes elsődleges rendezési szempontot.')
            ->assertViewHas('plans', fn ($rows) => $rows->total() === 1 && $rows->first()['id'] === $second->id);
    }

    public function test_two_level_shipment_order_matches_json_and_keeps_missing_dates_last_with_stable_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $alpha = Warehouse::create(['warehouse_name' => 'Alpha']);
        $zulu = Warehouse::create(['warehouse_name' => 'Zulu']);
        $other = Warehouse::create(['warehouse_name' => 'Outside']);
        $supervisor = $this->warehouseMember($alpha, 'supervisor');
        $z = $this->plan($admin, $zulu, 'Multi Z', ['planned_departure_at' => '2028-01-01T10:00:00Z']);
        $late = $this->plan($admin, $alpha, 'Multi late', ['planned_departure_at' => '2030-01-01T10:00:00Z']);
        $early = $this->plan($admin, $alpha, 'Multi early', ['planned_departure_at' => '2029-01-01T10:00:00Z']);
        $tie = $this->plan($admin, $alpha, 'Multi tie', ['planned_departure_at' => '2029-01-01T10:00:00Z']);
        $unknown = $this->plan($admin, $alpha, 'Multi unknown');
        $this->plan($admin, $other, 'Hidden outside');
        $this->actingAs($admin);
        $filters = ['search' => 'Multi', 'status' => 'draft', 'sort_by' => 'origin_warehouse', 'sort_dir' => 'asc',
            'then_by' => 'planned_departure_at', 'then_dir' => 'asc', 'per_page' => 2];
        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->get(route('shipments.index', $filters + ['page' => $page]))->assertOk();
            $rows = $response->viewData('plans');
            $ids = array_merge($ids, array_column($rows->items(), 'id'));
            $this->getJson(route('shipment-plans.index', $filters + ['page' => $page]))->assertOk()
                ->assertJsonPath('data', $rows->items());
            if ($page === 1) {
                parse_str(parse_url($rows->nextPageUrl(), PHP_URL_QUERY), $query);
                $this->assertSame('planned_departure_at', $query['then_by']);
                $this->assertSame('asc', $query['then_dir']);
                $this->assertSame('Multi', $query['search']);
                $this->assertSame('draft', $query['status']);
                $this->assertSame('2', $query['per_page']);
            }
        }
        $this->assertSame([$early->id, $tie->id, $late->id, $unknown->id, $z->id], $ids);
        $this->assertCount(5, array_unique($ids));
        $this->get(route('shipments.index', array_replace($filters, ['per_page' => 100, 'then_dir' => 'desc'])))
            ->assertOk()->assertViewHas('plans', fn ($rows) => array_column($rows->items(), 'id') === [$late->id, $early->id, $tie->id, $unknown->id, $z->id]);
        $this->actingAs($supervisor)->get(route('shipments.index', $filters))
            ->assertOk()->assertViewHas('plans', fn ($rows) => $rows->total() === 4 && array_column($rows->items(), 'id') === [$early->id, $tie->id]);
    }

    public function test_secondary_shipment_status_and_vehicle_order_and_invalid_parameters_are_safe(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = Warehouse::create(['warehouse_name' => 'Forrás']);
        $a = $this->plan($admin, $source, 'Vehicle A', ['vehicle_label' => 'A-01']);
        $b = $this->plan($admin, $source, 'Vehicle B', ['vehicle_label' => 'B-01']);
        $unknown = $this->plan($admin, $source, 'Vehicle unknown');
        $cancelled = $this->plan($admin, $source, 'Vehicle cancelled');
        $b->update(['status' => 'in_transit']);
        $unknown->update(['status' => 'completed']);
        $cancelled->update(['status' => 'cancelled']);
        $this->actingAs($admin);
        foreach (array_keys(\App\Services\ShipmentPlanReader::SORTS) as $field) {
            $this->get(route('shipments.index', ['sort_by' => 'origin_warehouse', 'sort_dir' => 'asc', 'then_by' => $field, 'then_dir' => 'desc']))
                ->assertOk()->assertViewHas('plans', fn ($rows) => $rows->total() === 4);
        }
        $base = ['sort_by' => 'origin_warehouse', 'sort_dir' => 'asc', 'then_by' => 'status', 'then_dir' => 'asc'];
        $this->get(route('shipments.index', $base))->assertOk()
            ->assertViewHas('plans', fn ($rows) => array_column($rows->items(), 'id') === [$a->id, $b->id, $unknown->id, $cancelled->id]);
        foreach (['asc' => [$a->id, $b->id, $unknown->id, $cancelled->id], 'desc' => [$b->id, $a->id, $unknown->id, $cancelled->id]] as $direction => $ids) {
            $this->get(route('shipments.index', array_replace($base, ['then_by' => 'vehicle_label', 'then_dir' => $direction])))->assertOk()
                ->assertViewHas('plans', fn ($rows) => array_column($rows->items(), 'id') === $ids);
        }
        $this->get(route('shipments.index', array_replace($base, ['then_by' => 'origin_warehouse'])))
            ->assertOk()->assertViewHas('filters', fn ($filters) => empty($filters['then_by']));
        $this->getJson(route('shipment-plans.index', array_replace($base, ['then_by' => 'id; DROP TABLE shipments', 'then_dir' => 'sideways'])))
            ->assertUnprocessable()->assertJsonValidationErrors(['then_by', 'then_dir']);
        $this->get(route('shipments.index', array_replace($base, ['search' => 'Vehicle A', 'then_by' => ['id'], 'then_dir' => ['desc']])))
            ->assertOk()->assertViewHas('filters', fn ($filters) => $filters['search'] === 'Vehicle A' && empty($filters['then_by']))
            ->assertViewHas('plans', fn ($rows) => $rows->total() === 1 && $rows->first()['id'] === $a->id);
    }

    private function warehouseMember(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function plan(User $actor, Warehouse $origin, string $reference, array $options = []): Shipment
    {
        $result = app(ShipmentPlanningService::class)->create($actor, $options + [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $origin->getKey(), 'reference' => $reference,
        ]);

        return Shipment::findOrFail($result['shipment']['id']);
    }
}
