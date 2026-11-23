<?php

namespace Tests\Feature;

use App\Models\{Category, Product, TransferIntent, User, Warehouse};
use App\Services\TransferIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferIntentPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $requester;
    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;
    private Warehouse $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23T14:23:37Z'));

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->requester = User::factory()->create(['role' => 'worker']);
        $this->source = Warehouse::create(['warehouse_name' => 'Központi mintaraktár', 'location' => 'Belső cím, 42.']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Déli mintaraktár']);
        $this->outside = Warehouse::create(['warehouse_name' => 'Külső mintaraktár']);
        $this->destination->memberships()->create(['user_id' => $this->requester->id, 'role' => 'requester']);
        $category = Category::create(['category_name' => 'Szerelvények']);
        $this->product = Product::create([
            'product_name' => 'Rozsdamentes alátét', 'sku' => 'ALATET-100',
            'category_id' => $category->category_id, 'price' => 3,
        ]);
    }

    public function test_module_list_filters_only_records_visible_to_the_user(): void
    {
        $mine = $this->intent($this->requester, $this->source, $this->destination, 'urgent');
        $hidden = $this->intent($this->admin, $this->outside, $this->source, 'critical');

        $this->actingAs($this->requester)->get(route('transfer-intent-pages.index', [
            'search' => 'ALATET Központi',
            'warehouse_id' => $this->source->getKey(),
            'status' => 'pending_approval',
            'priority' => 'urgent',
        ]))->assertOk()
            ->assertSee($mine->reference)->assertDontSee($hidden->reference)
            ->assertDontSee($this->outside->warehouse_name);
        $this->get(route('transfer-intent-pages.index', ['warehouse_id' => $this->outside->getKey()]))
            ->assertOk()->assertSee('Nincs a szűrésnek megfelelő igény.')->assertDontSee($hidden->reference);
        $this->get(route('transfer-intent-pages.index', ['status' => 'in_transit', 'search' => ['x']]))
            ->assertOk()->assertSee('Válassz érvényes állapotot.')->assertSee('A keresés legfeljebb 200 karakteres szöveg lehet.');
        $this->get(route('transfer-intent-pages.show', $hidden))->assertForbidden();
    }

    public function test_form_supports_destination_membership_and_exposes_only_names_of_counterpart_warehouses(): void
    {
        $this->get(route('transfer-intent-pages.create'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->get(route('transfer-intent-pages.create'))->assertForbidden();
        $this->actingAs($this->requester)->get(route('transfer-intent-pages.create'))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) =>
                $warehouses->pluck('warehouse_name')->contains($this->source->warehouse_name)
                && $warehouses->every(fn ($warehouse) => !array_key_exists('location', $warehouse->getAttributes())))
            ->assertDontSee($this->source->location)
            ->assertViewHas('requestWarehouseIds', [(string) $this->destination->getKey()]);
    }

    public function test_detail_refresh_only_returns_permitted_actions_and_public_audit_data(): void
    {
        $intent = $this->intent($this->requester, $this->source, $this->destination);
        $this->actingAs($this->requester)->get(route('transfer-intent-pages.show', $intent))->assertOk()
            ->assertSee($this->product->product_name)->assertSee('Eseménytörténet')
            ->assertViewHas('workflow', fn ($workflow) => $workflow['actions'] === ['cancel']);

        $url = route('transfer-intents.show', [$intent, 'workflow' => 1]);
        $this->getJson($url)->assertOk()->assertJsonPath('workflow.actions', ['cancel'])
            ->assertJsonPath('workflow.events.0.actor_name', $this->requester->name)
            ->assertJsonMissingPath('data.events.0.operation_key')->assertJsonMissingPath('data.events.0.metadata');

        $this->actingAs($this->admin)->getJson($url)->assertOk()
            ->assertJsonPath('workflow.actions', ['approve', 'reject', 'cancel']);
        app(TransferIntentService::class)->approve($this->admin, $intent, ['version' => 1, 'operation_key' => (string) Str::uuid()]);
        $this->getJson($url)->assertOk()->assertJsonPath('workflow.version', 2)
            ->assertJsonPath('workflow.actions', ['cancel'])->assertJsonPath('workflow.events.1.actor_name', $this->admin->name);
        $this->actingAs($this->requester)->getJson($url)->assertOk()->assertJsonPath('workflow.actions', []);
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    }

    public function test_single_sorting_preserves_scope_filters_and_numeric_pagination_with_missing_deadlines_last(): void
    {
        $low = $this->intent($this->requester, $this->source, $this->destination, 'normal', ['quantity' => 2, 'requested_arrival_at' => '2030-01-01T10:00:00Z']);
        $high = $this->intent($this->requester, $this->source, $this->destination, 'critical', ['quantity' => 30, 'requested_arrival_at' => '2029-01-01T10:00:00Z']);
        $unknown = $this->intent($this->requester, $this->source, $this->destination, 'urgent', ['quantity' => 10]);
        $hidden = $this->intent($this->admin, $this->outside, $this->source, 'normal', ['quantity' => 1]);
        $this->actingAs($this->requester);
        $base = ['search' => 'ALATET', 'warehouse_id' => $this->destination->getKey(), 'status' => 'pending_approval'];
        foreach (['asc' => [$low->id, $unknown->id, $high->id], 'desc' => [$high->id, $unknown->id, $low->id]] as $direction => $expected) {
            $this->get(route('transfer-intent-pages.index', $base + ['sort_by' => 'quantity', 'sort_dir' => $direction]))
                ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === $expected)
                ->assertDontSee($hidden->reference);
        }
        foreach (['asc' => [$high->id, $low->id, $unknown->id], 'desc' => [$low->id, $high->id, $unknown->id]] as $direction => $expected) {
            $this->get(route('transfer-intent-pages.index', $base + ['sort_by' => 'requested_arrival_at', 'sort_dir' => $direction]))
                ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === $expected);
        }
        $this->get(route('transfer-intent-pages.index', $base + ['sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 2, 'page' => 2]))
            ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->total() === 3 && $rows->pluck('id')->all() === [$high->id]);
        $this->get(route('transfer-intent-pages.index', $base + ['sort_by' => 'quantity', 'sort_dir' => 'asc']))
            ->assertOk()->assertSee('id="intent-filters"', false)
            ->assertSee('href="'.route('transfer-intent-pages.show', $low).'"', false)
            ->assertDontSee('Részletek ·')->assertDontSee('>Részletek', false);
        $this->get(route('transfer-intent-pages.index', $base + ['sort_by' => ['quantity'], 'sort_dir' => 'arbitrary']))
            ->assertOk()->assertSee('Válassz érvényes elsődleges rendezési szempontot.')
            ->assertSee('Válassz érvényes elsődleges rendezési irányt.')
            ->assertViewHas('filters', fn ($filters) => $filters['search'] === 'ALATET' && $filters['sort_by'] === 'created_at');
    }

    public function test_two_level_sorting_uses_business_priority_then_deadline_before_a_stable_id_and_pagination(): void
    {
        $normal = $this->intent($this->requester, $this->source, $this->destination, 'normal', ['quantity' => 2, 'requested_arrival_at' => '2029-01-01T10:00:00Z']);
        $urgentLate = $this->intent($this->requester, $this->source, $this->destination, 'urgent', ['quantity' => 100, 'requested_arrival_at' => '2030-01-01T10:00:00Z']);
        $criticalLate = $this->intent($this->requester, $this->source, $this->destination, 'critical', ['quantity' => 10, 'requested_arrival_at' => '2030-01-01T10:00:00Z']);
        $urgentEarly = $this->intent($this->requester, $this->source, $this->destination, 'urgent', ['quantity' => 2, 'requested_arrival_at' => '2029-01-01T10:00:00Z']);
        $criticalEarly = $this->intent($this->requester, $this->source, $this->destination, 'critical', ['quantity' => 2, 'requested_arrival_at' => '2029-01-01T10:00:00Z']);
        $urgentTie = $this->intent($this->requester, $this->source, $this->destination, 'urgent', ['quantity' => 10, 'requested_arrival_at' => '2029-01-01T10:00:00Z']);
        $urgentUnknown = $this->intent($this->requester, $this->source, $this->destination, 'urgent', ['quantity' => 2]);
        $hidden = $this->intent($this->admin, $this->outside, $this->source, 'critical', ['requested_arrival_at' => '2028-01-01T10:00:00Z']);
        $this->actingAs($this->requester);
        $filters = ['search' => 'ALATET', 'status' => 'pending_approval', 'warehouse_id' => $this->destination->getKey(),
            'sort_by' => 'priority', 'sort_dir' => 'desc', 'then_by' => 'requested_arrival_at', 'then_dir' => 'asc', 'per_page' => 3];
        $expected = [$criticalEarly->id, $criticalLate->id, $urgentTie->id, $urgentEarly->id, $urgentLate->id, $urgentUnknown->id, $normal->id];
        $actual = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->get(route('transfer-intent-pages.index', $filters + ['page' => $page]))->assertOk()->assertDontSee($hidden->reference);
            $rows = $response->viewData('intents');
            $this->assertSame(7, $rows->total());
            $actual = array_merge($actual, $rows->pluck('id')->all());
            if ($page === 1) {
                parse_str(parse_url($rows->nextPageUrl(), PHP_URL_QUERY), $query);
                $this->assertSame('requested_arrival_at', $query['then_by']);
                $this->assertSame('asc', $query['then_dir']);
                $this->assertSame('ALATET', $query['search']);
                $this->assertSame((string) $this->destination->getKey(), $query['warehouse_id']);
                $this->assertSame('3', $query['per_page']);
            }
        }
        $this->assertSame($expected, $actual);
        $this->assertCount(7, array_unique($actual));
        $this->get(route('transfer-intent-pages.index', array_replace($filters, ['per_page' => 100, 'then_dir' => 'desc'])))
            ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === [
                $criticalLate->id, $criticalEarly->id, $urgentLate->id, $urgentTie->id, $urgentEarly->id, $urgentUnknown->id, $normal->id]);
        $this->get(route('transfer-intent-pages.index', array_replace($filters, ['per_page' => 100, 'sort_dir' => 'asc', 'then_by' => 'quantity'])))
            ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === [
                $normal->id, $urgentEarly->id, $urgentUnknown->id, $urgentTie->id, $urgentLate->id, $criticalEarly->id, $criticalLate->id]);
    }

    public function test_all_intent_sort_fields_remain_available_and_duplicate_or_invalid_secondary_fields_are_safe(): void
    {
        $first = $this->intent($this->requester, $this->source, $this->destination);
        $second = $this->intent($this->requester, $this->source, $this->destination);
        $third = $this->intent($this->requester, $this->source, $this->destination);
        $fourth = $this->intent($this->requester, $this->source, $this->destination);
        $second->update(['status' => 'approved']);
        $third->update(['status' => 'rejected']);
        $fourth->update(['status' => 'cancelled']);
        $this->actingAs($this->requester);
        foreach (array_keys(\App\Http\Controllers\TransferIntentPageController::SORTS) as $field) {
            $this->get(route('transfer-intent-pages.index', ['sort_by' => 'product_name', 'sort_dir' => 'asc', 'then_by' => $field, 'then_dir' => 'desc']))
                ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->total() === 4);
        }
        $this->get(route('transfer-intent-pages.index', ['sort_by' => 'product_name', 'sort_dir' => 'asc', 'then_by' => 'status', 'then_dir' => 'asc']))
            ->assertOk()->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id, $third->id, $fourth->id]);
        $this->get(route('transfer-intent-pages.index', ['sort_by' => 'priority', 'sort_dir' => 'asc', 'then_by' => 'priority', 'then_dir' => 'desc']))
            ->assertOk()->assertViewHas('filters', fn ($filters) => empty($filters['then_by']))
            ->assertViewHas('intents', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id, $third->id, $fourth->id]);
        $this->get(route('transfer-intent-pages.index', ['search' => 'ALATET', 'sort_by' => 'priority', 'sort_dir' => 'asc',
            'then_by' => 'id; DROP TABLE transfer_intents', 'then_dir' => ['desc']]))
            ->assertOk()->assertViewHas('filters', fn ($filters) => $filters['search'] === 'ALATET' && $filters['sort_by'] === 'priority' && empty($filters['then_by']))
            ->assertViewHas('intents', fn ($rows) => $rows->total() === 4);
    }

    private function intent(User $actor, Warehouse $source, Warehouse $destination, string $priority = 'normal', array $overrides = []): TransferIntent
    {
        $result = app(TransferIntentService::class)->create($actor, $overrides + [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $destination->getKey(),
            'quantity' => 200, 'priority' => $priority,
        ]);

        return TransferIntent::findOrFail($result['intent']['id']);
    }
}
