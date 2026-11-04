<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Partner, Product, Stock, User, Warehouse};
use App\Services\InventoryService;
use App\Support\MovementListContext;
use Database\Seeders\PartnerMovementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerMovementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Warehouse $warehouse;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->user);
        $category = Category::create(['category_name' => 'Partner teszt']);
        $this->product = Product::create(['category_id' => $category->category_id, 'sku' => 'PARTNER-TEST', 'product_name' => 'Csavar', 'price' => 100]);
        $this->warehouse = Warehouse::create(['warehouse_name' => 'Partner raktár', 'location' => 'Budapest']);
        Stock::create(['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id, 'quantity' => 10]);
        $this->partner = Partner::create(['name' => 'Kettős partner', 'type' => 'both', 'email' => 'both@example.test']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'product_id' => $this->product->product_id,
            'warehouse_id' => $this->warehouse->warehouse_id, 'type' => 'in', 'quantity' => 2, 'partner_id' => $this->partner->id], $overrides);
    }

    public function test_partner_details_are_saved_searched_escaped_and_clearable(): void
    {
        $data = ['name' => 'Címzett partner', 'type' => 'both', 'contact_name' => 'Kiss Anna', 'country' => 'Magyarország',
            'postal_code' => '0010', 'city' => 'Győr', 'street_address' => 'Teszt utca 42.', 'notes' => "Átvétel 8–14\n<script>alert(1)</script>"];
        $this->post('/partners', $data)->assertRedirect()->assertSessionHasNoErrors();
        $partner = Partner::where('name', $data['name'])->sole();
        foreach ($data as $key => $value) $this->assertSame($value, $partner->{$key});
        $this->get(route('partners.edit', $partner))->assertOk()->assertSee('Kiss Anna')->assertSee('0010')->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/partners?search=Kiss+Győr')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$partner->id]);
        $this->put(route('partners.update', $partner), ['lock_version' => $partner->fresh()->lock_version, 'name' => $data['name'], 'type' => 'both'])->assertRedirect();
        foreach (array_diff(array_keys($data), ['name', 'type']) as $field) $this->assertNull($partner->fresh()->{$field});
    }

    public static function invalidDetails(): array
    {
        $cases = [];
        foreach (['contact_name' => 100, 'country' => 100, 'postal_code' => 20, 'city' => 100, 'street_address' => 255, 'notes' => 2000] as $field => $max) {
            $cases[$field.' array'] = [$field, ['bad']];
            $cases[$field.' length'] = [$field, str_repeat('á', $max + 1)];
        }
        return $cases;
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_are_rejected_without_writes(string $field, mixed $value): void
    {
        $this->postJson('/partners', ['name' => 'Nem menthető', 'type' => 'both', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('partners', 1);
    }

    public function test_duplicate_warning_preserves_all_new_fields(): void
    {
        $data = ['name' => $this->partner->name, 'type' => 'both', 'contact_name' => 'Új kapcsolattartó', 'country' => 'HU',
            'postal_code' => '1234', 'city' => 'Pécs', 'street_address' => 'Utca 1.', 'notes' => 'Áruátvétel délelőtt'];
        $response = $this->post('/partners', $data)->assertSessionHas('duplicate_warning', true);
        foreach ($data as $key => $value) $this->assertSame($value, session()->getOldInput($key));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Új kapcsolattartó')->assertSee('Áruátvétel délelőtt')->assertSee('Utca 1.');
        $this->assertDatabaseCount('partners', 1);
    }

    public function test_dual_role_partner_is_in_both_filters_and_type_sort_is_stable(): void
    {
        $supplier = Partner::create(['name' => 'Beszállító', 'type' => 'supplier']);
        $customer = Partner::create(['name' => 'Vevő', 'type' => 'customer']);
        foreach (['supplier' => $supplier, 'customer' => $customer] as $type => $single) {
            $rows = $this->get('/partners?type='.$type)->assertOk()->viewData('partners');
            $this->assertEqualsCanonicalizing([$single->id, $this->partner->id], $rows->pluck('id')->all());
        }
        $this->get('/partners?type=both')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$this->partner->id]);
        $this->get('/partners?sort_by=type')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$supplier->id, $customer->id, $this->partner->id]);
    }

    public static function movementRoles(): array
    {
        return [['supplier', 'in', true], ['customer', 'out', true], ['both', 'in', true], ['both', 'out', true],
            ['supplier', 'out', false], ['customer', 'in', false]];
    }

    #[DataProvider('movementRoles')]
    public function test_movement_partner_role_is_enforced_atomically(string $role, string $type, bool $allowed): void
    {
        $this->partner->update(['type' => $role]);
        $data = $this->payload(['type' => $type]);
        $response = $this->postJson('/movements', $data);
        if ($allowed) {
            $response->assertRedirect();
            $movement = InventoryMovement::sole();
            $this->assertSame($this->partner->id, $movement->partner_id);
            $this->assertSame($this->partner->name, $movement->partner_name);
            $this->assertSame($type === 'in' ? 12 : 8, Stock::sole()->quantity);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('partner_id');
            $this->assertDatabaseCount('inventory_movements', 0);
            $this->assertDatabaseCount('inventory_movement_requests', 0);
            $this->assertSame(10, Stock::sole()->quantity);
        }
    }

    public function test_archived_partner_and_forged_ids_are_rejected_without_stock_changes(): void
    {
        $this->partner->forceFill(['archived_at' => now()])->save();
        foreach ([$this->partner->id, 99999, ['bad']] as $id) $this->postJson('/movements', $this->payload(['partner_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('partner_id');
        $this->assertDatabaseCount('inventory_movement_requests', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(10, Stock::sole()->quantity);
        $this->get('/movements/create')->assertViewHas('partners', fn ($p) => $p->isEmpty());
    }

    public function test_original_request_replays_after_rename_archival_and_role_change_and_keeps_snapshot(): void
    {
        $data = $this->payload();
        $this->post('/movements', $data)->assertSessionHasNoErrors();
        $movement = InventoryMovement::sole();
        $this->partner->forceFill(['name' => 'Átnevezve', 'archived_at' => now(), 'type' => 'customer'])->save();
        $this->post('/movements', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(12, Stock::sole()->quantity);
        $this->assertSame('Kettős partner', $movement->fresh()->partner_name);
        $this->get(route('movements.show', $movement))->assertOk()->assertSee('Kettős partner')->assertSee('Átnevezve')->assertSee('Archivált');
        $this->postJson('/movements', array_replace($data, ['partner_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame(12, Stock::sole()->quantity);
    }

    public function test_changed_partner_cannot_reuse_request_key_and_legacy_hashes_still_replay(): void
    {
        $data = $this->payload(['partner_id' => null]);
        $this->post('/movements', $data)->assertSessionHasNoErrors();
        $legacyPayload = ['product_id' => $this->product->product_id, 'warehouse_id' => $this->warehouse->warehouse_id,
            'user_id' => $this->user->id, 'type' => 'in', 'quantity' => 2, 'reference' => null];
        $this->assertSame(hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR)), DB::table('inventory_movement_requests')->value('payload_hash'));
        $this->post('/movements', $data)->assertSessionHasNoErrors();
        $this->postJson('/movements', array_replace($data, ['partner_id' => $this->partner->id]))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(12, Stock::sole()->quantity);
    }

    public function test_linked_partner_cannot_be_deleted_but_can_be_archived_and_remains_filterable(): void
    {
        $this->post('/movements', $this->payload())->assertSessionHasNoErrors();
        $this->delete(route('partners.destroy', $this->partner))->assertSessionHasErrors('partner');
        $this->assertModelExists($this->partner);
        $this->patch(route('partners.archive', $this->partner))->assertRedirect();
        $this->get('/partners/'.$this->partner->id.'/edit')->assertOk()->assertSee('Készletmozgások (1)')->assertSee('Bevételezések (1)')->assertSee('Kiadások (0)');
        $response = $this->get('/movements?partner_id='.$this->partner->id)->assertOk();
        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertStringContainsString('Archivált', $response->viewData('filterLabels')['partner_id']);
        $this->assertStringContainsString('partner_id='.$this->partner->id, MovementListContext::url($response->viewData('listContext')));
        $this->assertSame(12, Stock::sole()->quantity);
    }

    public function test_partner_filter_and_current_or_historic_name_search_combine_with_other_filters(): void
    {
        $this->post('/movements', $this->payload(['reference' => 'EGYEDI-REF']));
        $this->post('/movements', $this->payload(['partner_id' => null, 'reference' => 'MASIK-REF']));
        $this->partner->update(['name' => 'Új cégnév']);
        foreach (['Kettős', 'Új cégnév'] as $name) {
            $response = $this->get(route('movements.index', ['search' => $name, 'partner_id' => $this->partner->id]))->assertOk();
            $this->assertSame(1, $response->viewData('movements')->total());
        }
        $this->get('/movements?partner_id='.$this->partner->id.'&type=out')->assertViewHas('movements', fn ($p) => $p->isEmpty());
        $this->getJson('/movements?partner_id=999999')->assertUnprocessable()->assertJsonValidationErrors('partner_id');
    }

    public function test_pdf_partner_option_keeps_snapshot_and_never_includes_internal_notes(): void
    {
        $this->post('/movements', $this->payload());
        $movement = InventoryMovement::sole();
        $this->partner->update(['name' => 'Új partnernév', 'notes' => 'BELSŐ-TITKOS-MEGJEGYZÉS']);
        $options = ['type_filter' => 'all', 'show_warehouse' => true, 'show_reference' => false, 'show_partner' => true,
            'show_user' => true, 'show_summary' => true, 'show_signatures' => false];
        $html = view('pdf.movements', ['movements' => collect([$movement]), 'options' => $options])->render();
        $this->assertStringContainsString('Partner: Kettős partner', $html);
        $this->assertStringNotContainsString('BELSŐ-TITKOS-MEGJEGYZÉS', $html);
        $this->assertStringNotContainsString('Új partnernév', $html);
        $options['show_partner'] = false;
        $html = view('pdf.movements', ['movements' => collect([$movement]), 'options' => $options])->render();
        $this->assertStringNotContainsString('Kettős partner', $html);
        $this->get(route('export.movements', ['partner_id' => $this->partner->id, 'show_partner' => 1, 'preview' => 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_partner_snapshot_cannot_be_spoofed_and_stock_failure_rolls_back_partner_movement(): void
    {
        $this->post('/movements', $this->payload(['partner_name' => 'Hamis név']))->assertSessionHasNoErrors();
        $this->assertSame($this->partner->name, InventoryMovement::sole()->partner_name);
        $this->postJson('/movements', $this->payload(['type' => 'out', 'quantity' => 999]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
        $this->assertSame(12, Stock::sole()->quantity);
    }

    public function test_partner_demo_seed_is_repeatable_after_user_edits_and_archive_without_changing_stock(): void
    {
        $this->user->update(['email' => 'worker@raktar.hu']);
        $this->seed(PartnerMovementDemoSeeder::class);
        $demo = Partner::where('email', 'partner-demo@example.test')->sole();
        $this->assertSame('both', $demo->type);
        $this->assertSame('Minta Anna', $demo->contact_name);
        $this->assertSame(2, $demo->movements()->count());
        $demo->forceFill(['name' => 'Felhasználó által átnevezve', 'email' => 'changed-demo@example.test', 'notes' => 'Saját megjegyzés', 'type' => 'customer', 'archived_at' => now()])->save();
        $snapshot = fn () => collect(['partners', 'stocks', 'inventory_movements', 'inventory_movement_requests', 'products', 'warehouses'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array)$row)->all()])->all();
        $before = $snapshot();
        $this->travel(2)->hours();
        $this->seed(PartnerMovementDemoSeeder::class);
        $this->assertSame($before, $snapshot());
        $this->assertSame(15, Stock::where('product_id', Product::where('sku', 'DEMO-PARTNER-CSAVAR')->sole()->product_id)->sole()->quantity);
    }

    public function test_database_foreign_key_protects_partner_history_even_when_controller_is_bypassed(): void
    {
        $this->post('/movements', $this->payload())->assertSessionHasNoErrors();
        try {
            DB::table('partners')->where('id', $this->partner->id)->delete();
            $this->fail('A mozgáshoz kapcsolódó partner SQL-ből sem törölhető.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $e->getMessage());
        }
        $this->assertModelExists($this->partner);
        $this->assertSame($this->partner->id, InventoryMovement::sole()->partner_id);
        $this->assertSame(12, Stock::sole()->quantity);
    }
}
