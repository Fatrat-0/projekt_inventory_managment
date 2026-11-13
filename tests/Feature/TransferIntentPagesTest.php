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

    private function intent(User $actor, Warehouse $source, Warehouse $destination, string $priority = 'normal'): TransferIntent
    {
        $result = app(TransferIntentService::class)->create($actor, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $destination->getKey(),
            'quantity' => 200, 'priority' => $priority,
        ]);

        return TransferIntent::findOrFail($result['intent']['id']);
    }
}
