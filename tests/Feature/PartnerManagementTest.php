<?php

namespace Tests\Feature;

use App\Models\{Partner, User};
use App\Support\PartnerListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerManagementTest extends TestCase
{
    use RefreshDatabase;
    private Partner $customer;
    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->customer = Partner::create(['name' => 'Alpha 100%_!', 'type' => 'customer', 'email' => 'alpha@example.test', 'phone' => '+36 20 123 4567']);
        $this->supplier = Partner::create(['name' => 'Beta Kft.', 'type' => 'supplier', 'email' => 'beta@example.test', 'phone' => null]);
    }

    public function test_search_combines_words_across_fields_and_escapes_like_wildcards(): void
    {
        foreach (['Alpha example 123', '100%_!'] as $search) {
            $this->get(route('partners.index', ['search' => $search]))->assertOk()
                ->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->customer->id]);
        }
        $this->get('/partners?search=Alpha%20Beta')->assertViewHas('partners', fn ($rows) => $rows->total() === 0);
        $this->get('/partners?search=NO-PARTNER')->assertOk()->assertSee('Nincs a feltételeknek megfelelő partner.');
    }

    public function test_contact_type_search_and_exact_filters_are_combined_with_and(): void
    {
        Partner::create(['name' => 'Missing customer', 'type' => 'customer', 'email' => null, 'phone' => '123']);
        $this->get('/partners?type=customer&contact=missing')->assertViewHas('partners', fn ($rows) => $rows->pluck('name')->all() === ['Missing customer']);
        $this->get('/partners?type=supplier&contact=complete')->assertViewHas('partners', fn ($rows) => $rows->isEmpty());
        $this->get('/partners?search=Alpha&contact=missing')->assertViewHas('partners', fn ($rows) => $rows->isEmpty());
        $this->get(route('partners.index', ['partner_id' => $this->customer->id, 'contact' => 'missing']))->assertViewHas('partners', fn ($rows) => $rows->isEmpty());
        $this->get(route('partners.index', ['partner_id' => $this->supplier->id, 'type' => 'customer']))->assertViewHas('partners', fn ($rows) => $rows->isEmpty());
    }

    public function test_contact_completeness_handles_null_empty_whitespace_and_zero_string(): void
    {
        $legacy = Partner::create(['name' => 'Legacy blanks', 'type' => 'supplier', 'email' => '   ', 'phone' => '']);
        $zero = Partner::create(['name' => 'Zero phone', 'type' => 'customer', 'email' => 'zero@example.test', 'phone' => '0']);
        $this->get('/partners?contact=complete')->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->customer->id, $zero->id]);
        $this->get('/partners?contact=missing')->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->supplier->id, $legacy->id]);
    }

    public function test_type_sort_follows_displayed_hungarian_labels(): void
    {
        $this->get('/partners?sort_by=type&sort_dir=asc')->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->supplier->id, $this->customer->id]);
        $this->get('/partners?sort_by=type&sort_dir=desc')->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->customer->id, $this->supplier->id]);
    }

    public function test_missing_contact_values_sort_last_in_both_directions(): void
    {
        $empty = Partner::create(['name' => 'Empty', 'type' => 'supplier', 'email' => null, 'phone' => '']);
        foreach (['asc', 'desc'] as $direction) {
            $rows = $this->get('/partners?sort_by=phone&sort_dir='.$direction)->assertOk()->viewData('partners');
            $this->assertSame($this->customer->id, $rows->first()->id);
            $this->assertEqualsCanonicalizing([$this->supplier->id, $empty->id], $rows->pluck('id')->slice(1)->all());
        }
    }

    public function test_pagination_is_stable_and_unavailable_pages_recover_without_losing_filters(): void
    {
        for ($i = 0; $i < 20; $i++) Partner::create(['name' => 'Same', 'type' => 'customer']);
        $first = $this->get('/partners?search=Same&per_page=15')->assertOk()->viewData('partners');
        $second = $this->get('/partners?search=Same&per_page=15&page=2')->assertOk()->viewData('partners');
        $this->assertCount(15, $first); $this->assertCount(5, $second);
        $this->assertCount(0, array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));
        $this->assertSame(20, $first->total());
        $response = $this->get('/partners?search=Same&per_page=15&page=999&sort_by=phone&sort_dir=desc');
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('page=2', $location);
        $this->assertStringContainsString('search=Same', $location);
        $this->assertStringContainsString('sort_by=phone', $location);
        $this->get($location)->assertOk();
        $response = $this->get('/partners?search=NO-PARTNER&page=999');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertArrayNotHasKey('page', $params);
        $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('partners', fn ($rows) => $rows->total() === 0);
    }

    public function test_exact_suggestions_distinguish_duplicate_names_and_queries_are_read_only(): void
    {
        $duplicate = Partner::create(['name' => $this->customer->name, 'type' => 'supplier', 'email' => 'other@example.test']);
        $before = Partner::orderBy('id')->get()->toArray();
        $response = $this->get(route('partners.index', ['partner_id' => $duplicate->id]))->assertOk();
        $response->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$duplicate->id]);
        $suggestions = $response->viewData('suggestions')->keyBy('value');
        $this->assertStringContainsString('#'.$duplicate->id, $suggestions[$duplicate->id]['detail']);
        $this->assertStringContainsString('other@example.test', $suggestions[$duplicate->id]['detail']);
        $this->assertSame($before, Partner::orderBy('id')->get()->toArray());
    }

    public function test_creation_update_clearing_optional_contacts_and_context_round_trip(): void
    {
        $filters = ['search' => 'Test', 'type' => 'supplier', 'contact' => 'complete', 'sort_by' => 'email', 'sort_dir' => 'desc', 'per_page' => 25, 'page' => 2];
        $context = PartnerListContext::encode($filters);
        $this->get(route('partners.create', ['context' => $context, 'type' => 'supplier']))->assertOk()
            ->assertViewHas('defaultType', 'supplier')->assertViewHas('returnUrl', route('partners.index', $filters));
        $this->post('/partners', ['name' => '  Test Partner  ', 'type' => 'supplier', 'email' => 'test@example.test', 'phone' => '+36 (1) 234-5678', 'context' => $context])->assertRedirect(route('partners.index', $filters));
        $partner = Partner::where('name', 'Test Partner')->firstOrFail();
        $this->get(route('partners.edit', ['partner' => $partner, 'context' => $context]))->assertOk()->assertViewHas('returnUrl', route('partners.index', $filters));
        $this->put(route('partners.update', $partner), ['name' => 'Updated', 'type' => 'customer', 'email' => '', 'phone' => '   ', 'context' => $context, 'id' => $this->customer->id])->assertRedirect(route('partners.index', $filters));
        $this->assertNull($partner->fresh()->email); $this->assertNull($partner->fresh()->phone);
        $this->assertSame('customer', $partner->fresh()->type);
        $this->assertSame('Alpha 100%_!', $this->customer->fresh()->name);
    }

    public function test_duplicate_names_and_shared_email_addresses_remain_allowed(): void
    {
        $data = ['name' => $this->customer->name, 'type' => 'supplier', 'email' => $this->customer->email];
        $this->post('/partners', $data)->assertRedirect();
        $this->assertSame(2, Partner::where('email', $data['email'])->count());
    }

    public function test_deletion_clears_exact_filter_and_page_but_preserves_other_list_state(): void
    {
        $context = PartnerListContext::encode(['partner_id' => $this->customer->id, 'search' => 'Alpha', 'type' => 'customer', 'page' => 3]);
        $this->delete(route('partners.destroy', $this->customer), ['context' => $context])->assertRedirect(route('partners.index', ['search' => 'Alpha', 'type' => 'customer']));
        $this->assertModelMissing($this->customer); $this->assertModelExists($this->supplier);
    }

    public static function badFields(): array
    {
        return [['name', ''], ['name', str_repeat('x', 101)], ['name', ['bad']], ['name', 123], ['type', 'bad'], ['type', ['bad']],
            ['email', 'invalid'], ['email', str_repeat('x', 90).'@example.test'], ['email', ['bad']], ['phone', str_repeat('1', 21)], ['phone', ['bad']], ['phone', 123]];
    }

    #[DataProvider('badFields')]
    public function test_invalid_writes_are_rejected_without_partial_changes(string $field, mixed $value): void
    {
        $data = array_replace(['name' => 'Valid', 'type' => 'customer'], [$field => $value]);
        $this->postJson('/partners', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson(route('partners.update', $this->customer), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('partners', 2);
        $this->assertSame('Alpha 100%_!', $this->customer->fresh()->name);
    }

    public static function badFilters(): array
    {
        return [['search', ['bad']], ['search', str_repeat('x', 201)], ['type', 'bad'], ['contact', 'bad'], ['sort_by', 'name;DROP TABLE partners'],
            ['sort_dir', 'bad'], ['per_page', 1], ['page', 0], ['partner_id', 999999], ['partner_id', ['bad']]];
    }

    #[DataProvider('badFilters')]
    public function test_bad_filters_recover_for_html_and_return_field_errors_for_json(string $key, mixed $value): void
    {
        $url = route('partners.index', [$key => $value]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->get($url)->assertRedirect(route('partners.index'))->assertSessionHasErrors('filters');
    }

    public function test_malformed_old_input_renders_safely_and_restores_list_context(): void
    {
        $url = route('partners.edit', $this->customer);
        $this->from($url)->put(route('partners.update', $this->customer), ['name' => ['bad'], 'type' => ['bad'], 'email' => ['bad'], 'phone' => ['bad'], 'context' => ['bad']])
            ->assertRedirect($url)->assertSessionHasErrors(['name', 'type', 'email', 'phone']);
        $this->get($url)->assertOk();
        $this->get(route('partners.create', ['type' => ['bad']]))->assertOk()->assertViewHas('defaultType', 'customer');
    }

    public function test_context_is_local_bounded_and_whitelisted(): void
    {
        foreach (['https://evil.example', ['bad'], str_repeat('x', 5000), Crypt::encryptString('{"search":"safe","redirect":"https://evil.example","type":["bad"]}')] as $token) {
            $url = PartnerListContext::url($token);
            $this->assertStringStartsWith(route('partners.index'), $url);
            $this->assertStringNotContainsString('evil.example', $url);
        }
    }

    public function test_names_and_contacts_are_escaped_and_email_uri_is_encoded(): void
    {
        $name = '<script>alert(1)</script>';
        $partner = Partner::create(['name' => $name, 'type' => 'supplier', 'email' => 'office+sales@example.test', 'phone' => '<svg/onload=x>']);
        $this->get('/partners')->assertOk()->assertSee(e($name), false)->assertDontSee($name, false)
            ->assertDontSee('<svg/onload=x>', false)->assertSee('mailto:office%2Bsales%40example.test', false);
        $this->get(route('partners.edit', $partner))->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
    }

    public function test_missing_partner_routes_return_404_without_affecting_other_records(): void
    {
        $this->get('/partners/999999/edit')->assertNotFound();
        $this->put('/partners/999999', ['name' => 'Unknown', 'type' => 'customer'])->assertNotFound();
        $this->delete('/partners/999999')->assertNotFound();
        $this->assertDatabaseCount('partners', 2);
    }

    public static function roles(): array { return [['admin'], ['manager'], ['worker']]; }

    #[DataProvider('roles')]
    public function test_existing_roles_can_perform_the_full_partner_workflow(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get('/partners')->assertOk(); $this->get('/partners/create')->assertOk();
        $this->get(route('partners.edit', $this->customer))->assertOk();
        $this->post('/partners', ['name' => 'Role partner', 'type' => 'customer'])->assertRedirect();
        $this->put(route('partners.update', $this->customer), ['name' => 'Role update', 'type' => 'supplier'])->assertRedirect();
        $this->delete(route('partners.destroy', $this->customer))->assertRedirect();
    }

    public function test_guests_and_unknown_roles_cannot_manage_partners(): void
    {
        $user = User::factory()->create(['role' => 'worker']); $user->role = 'unknown'; $this->actingAs($user);
        $this->get('/partners')->assertForbidden(); $this->get('/partners/create')->assertForbidden();
        $this->get(route('partners.edit', $this->customer))->assertForbidden();
        $this->post('/partners', ['name' => 'Forbidden', 'type' => 'customer'])->assertForbidden();
        $this->put(route('partners.update', $this->customer), ['name' => 'Forbidden', 'type' => 'customer'])->assertForbidden();
        $this->delete(route('partners.destroy', $this->customer))->assertForbidden();
        auth()->forgetGuards();
        $this->get('/partners')->assertRedirect(route('login'));
        $this->post('/partners', ['name' => 'Guest', 'type' => 'customer'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('partners', 2);
    }
}
