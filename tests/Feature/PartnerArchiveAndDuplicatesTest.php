<?php

namespace Tests\Feature;

use App\Models\{Partner, User};
use App\Services\PartnerDuplicateCheck;
use App\Support\PartnerListContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerArchiveAndDuplicatesTest extends TestCase
{
    use RefreshDatabase;
    private Partner $active;
    private Partner $archived;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->active = Partner::create(['name' => 'ÁrvÍz  Kft.', 'type' => 'customer', 'email' => 'Office@Example.test', 'phone' => '123']);
        $this->archived = Partner::create(['name' => 'Régi partner', 'type' => 'supplier', 'email' => 'old@example.test']);
        $this->archived->forceFill(['archived_at' => now()->subDay()])->save();
    }

    private function check(array $data, ?Partner $except = null): array
    {
        return app(PartnerDuplicateCheck::class)->check($data['name'] ?? '', $data['email'] ?? '', $except);
    }

    public function test_default_active_archived_and_all_scopes_apply_to_rows_and_search_suggestions(): void
    {
        foreach (['active' => [$this->active->id], 'archived' => [$this->archived->id], 'all' => [$this->active->id, $this->archived->id]] as $status => $expected) {
            $response = $this->get('/partners?status='.$status)->assertOk();
            $this->assertEqualsCanonicalizing($expected, $response->viewData('partners')->pluck('id')->all());
            $this->assertEqualsCanonicalizing(array_map('strval', $expected), $response->viewData('suggestions')->pluck('value')->all());
        }
        $this->get('/partners')->assertViewHas('partners', fn ($rows) => $rows->pluck('id')->all() === [$this->active->id]);
        $this->get('/partners?status=archived&type=customer')->assertViewHas('partners', fn ($rows) => $rows->isEmpty());
        $this->get(route('partners.index', ['partner_id' => $this->archived->id]))->assertViewHas('partners', fn ($rows) => $rows->isEmpty())->assertViewHas('selectedPartner', fn ($p) => $p->id === $this->archived->id);
        $this->getJson('/partners?status=invalid')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson(route('partners.index', ['status' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->get('/partners?status=invalid')->assertRedirect(route('partners.index'));
    }

    public function test_archive_and_restore_are_idempotent_and_preserve_identity_contacts_and_context(): void
    {
        $before = $this->active->only(['id', 'name', 'type', 'email', 'phone', 'created_at']);
        $context = PartnerListContext::encode(['status' => 'all', 'type' => 'customer', 'search' => 'Árv', 'partner_id' => $this->active->id, 'page' => 3, 'per_page' => 25]);
        $url = route('partners.index', ['search' => 'Árv', 'type' => 'customer', 'status' => 'all', 'per_page' => 25]);
        $this->patch(route('partners.archive', $this->active), ['context' => $context])->assertRedirect($url);
        $this->assertTrue($this->active->fresh()->isArchived());
        $archivedAt = $this->active->fresh()->archived_at;
        $updatedAt = $this->active->fresh()->updated_at;
        $this->travel(1)->hour();
        $this->patch(route('partners.archive', $this->active), ['context' => $context])->assertRedirect($url);
        $this->assertTrue($archivedAt->equalTo($this->active->fresh()->archived_at));
        $this->assertTrue($updatedAt->equalTo($this->active->fresh()->updated_at));
        $this->patch(route('partners.restore', $this->active), ['context' => $context])->assertRedirect($url);
        $this->assertFalse($this->active->fresh()->isArchived());
        $restoredAt = $this->active->fresh()->updated_at;
        $this->travel(1)->hour();
        $this->patch(route('partners.restore', $this->active))->assertRedirect();
        $this->assertTrue($restoredAt->equalTo($this->active->fresh()->updated_at));
        $this->assertEquals($before, $this->active->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('partners', 2);
    }

    public function test_archived_partner_is_editable_without_implicitly_restoring_it_and_can_be_deleted(): void
    {
        $this->get(route('partners.edit', $this->archived))->assertOk()->assertSee('Archivált partner.')->assertSee('Visszaállítás');
        $this->put(route('partners.update', $this->archived), ['lock_version' => $this->archived->fresh()->lock_version, 'name' => 'Átnevezett régi', 'type' => 'supplier', 'archived_at' => null])->assertRedirect();
        $this->assertTrue($this->archived->fresh()->isArchived());
        $this->delete(route('partners.destroy', $this->archived))->assertRedirect();
        $this->assertModelMissing($this->archived);
    }

    public function test_state_cannot_be_set_through_regular_store_or_update_payload(): void
    {
        $this->post('/partners', ['name' => 'Új aktív', 'type' => 'supplier', 'archived_at' => now()->toDateTimeString()])->assertRedirect();
        $new = Partner::where('name', 'Új aktív')->sole();
        $this->assertFalse($new->isArchived());
        $this->put(route('partners.update', $new), ['lock_version' => $new->fresh()->lock_version, 'name' => 'Új aktív', 'type' => 'customer', 'archived_at' => now()->toDateTimeString()])->assertRedirect();
        $this->assertFalse($new->fresh()->isArchived());
    }

    public function test_duplicate_lookup_normalizes_unicode_case_spaces_and_email_and_includes_archived_records(): void
    {
        $before = Partner::orderBy('id')->get()->toArray();
        $response = $this->getJson(route('partners.duplicates', ['name' => '  árvíz   kft. ', 'email' => ' OFFICE@example.TEST ']))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('matches.0.id', $this->active->id)
            ->assertJsonPath('matches.0.same_name', true)->assertJsonPath('matches.0.same_email', true);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson(route('partners.duplicates', ['email' => 'OLD@example.test']))->assertOk()->assertJsonPath('matches.0.archived', true);
        $this->getJson(route('partners.duplicates-edit', ['partner' => $this->active, 'name' => $this->active->name, 'email' => $this->active->email]))->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($before, Partner::orderBy('id')->get()->toArray());
    }

    public function test_blank_fields_and_partial_or_wildcard_text_do_not_create_false_positive_duplicates(): void
    {
        Partner::create(['name' => '100%_!', 'type' => 'customer']);
        foreach ([['name' => '', 'email' => ''], ['name' => 'Árv'], ['name' => '%'], ['email' => '@example.test']] as $data) {
            $this->assertSame(0, $this->check($data)['total']);
        }
        $this->assertSame(1, $this->check(['name' => '100%_!'])['total']);
    }

    public function test_matching_by_either_name_or_email_finds_both_records_once_and_limits_only_displayed_results(): void
    {
        for ($i = 0; $i < 7; $i++) Partner::create(['name' => 'Egyező', 'type' => 'customer', 'email' => 'shared@example.test']);
        $check = $this->check(['name' => 'egyező', 'email' => 'SHARED@example.test']);
        $this->assertSame(7, $check['total']); $this->assertCount(5, $check['matches']);
        $this->assertCount(5, array_unique(array_column($check['matches'], 'id')));
        Partner::create(['name' => 'Másik név', 'type' => 'supplier', 'email' => 'shared@example.test']);
        $changed = $this->check(['name' => 'egyező', 'email' => 'shared@example.test']);
        $this->assertSame(8, $changed['total']); $this->assertNotSame($check['token'], $changed['token']);
    }

    public function test_html_save_without_confirmation_warns_without_writing_then_allows_explicit_confirmation(): void
    {
        $data = ['name' => 'árvíz kft.', 'type' => 'supplier', 'email' => 'office@example.test', 'phone' => '987'];
        $context = PartnerListContext::encode(['type' => 'supplier', 'status' => 'all', 'per_page' => 25]);
        $data['context'] = $context;
        $response = $this->post('/partners', $data)->assertRedirect()->assertSessionHas('duplicate_warning', true);
        $this->assertDatabaseCount('partners', 2);
        $form = $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('returnUrl', PartnerListContext::url($context));
        $this->assertSame(1, $form->viewData('duplicates')['total']);
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $form->viewData('duplicates')['token']])->assertRedirect(PartnerListContext::url($context));
        $this->assertDatabaseCount('partners', 3);
    }

    public function test_duplicate_edit_requires_confirmation_but_unchanged_record_does_not_match_itself(): void
    {
        $data = ['name' => $this->active->name, 'type' => 'customer', 'email' => $this->active->email];
        $this->put(route('partners.update', $this->active), array_replace($data, ['lock_version' => $this->active->fresh()->lock_version]))->assertRedirect()->assertSessionMissing('duplicate_warning');
        $data['name'] = $this->archived->name;
        $this->put(route('partners.update', $this->active), array_replace($data, ['lock_version' => $this->active->fresh()->lock_version]))->assertSessionHas('duplicate_warning', true);
        $this->assertSame('ÁrvÍz  Kft.', $this->active->fresh()->name);
        $check = $this->check($data, $this->active);
        $this->put(route('partners.update', $this->active), array_replace($data, ['lock_version' => $this->active->fresh()->lock_version]) + ['confirm_duplicates' => 1, 'duplicate_token' => $check['token']])->assertRedirect();
        $this->assertSame('Régi partner', $this->active->fresh()->name);
    }

    public function test_confirmation_is_invalidated_when_fields_candidates_or_archive_status_change(): void
    {
        $data = ['name' => 'árvíz kft.', 'email' => 'office@example.test', 'type' => 'supplier'];
        $token = $this->check($data)['token'];
        $changedFields = array_replace($data, ['name' => 'Másik']);
        $this->post('/partners', $changedFields + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        Partner::create(['name' => 'árvíz kft.', 'type' => 'supplier']);
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        $token = $this->check($data)['token'];
        $this->active->forceFill(['archived_at' => now()])->save();
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        $this->assertDatabaseCount('partners', 3);
    }

    public function test_forged_missing_or_cross_record_confirmation_is_not_accepted(): void
    {
        $data = ['name' => 'árvíz kft.', 'type' => 'supplier'];
        foreach (['', str_repeat('a', 64), $this->check($data, $this->archived)['token']] as $token) {
            $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        }
        $this->assertDatabaseCount('partners', 2);
    }

    public function test_invalid_duplicate_fields_are_rejected_and_links_are_safe(): void
    {
        foreach ([['name' => ['bad']], ['name' => str_repeat('x', 101)], ['email' => ['bad']]] as $data) $this->getJson(route('partners.duplicates', $data))->assertUnprocessable();
        $evil = Partner::create(['name' => '<script>alert(1)</script>', 'type' => 'customer']);
        $check = $this->check(['name' => $evil->name]);
        $this->assertSame(route('partners.edit', $evil), $check['matches'][0]['edit_url']);
        $response = $this->post('/partners', ['name' => $evil->name, 'type' => 'supplier'])->assertSessionHas('duplicate_warning', true);
        $form = $this->get($response->headers->get('Location'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame($evil->name, $form->viewData('duplicates')['matches'][0]['name']);
    }

    public function test_removed_or_modified_candidate_requires_fresh_confirmation(): void
    {
        $data = ['name' => 'Új név', 'email' => $this->active->email, 'type' => 'customer'];
        $second = Partner::create(['name' => 'Második egyezés', 'email' => $this->active->email, 'type' => 'supplier']);
        $token = $this->check($data)['token'];
        $second->delete();
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        $token = $this->check($data)['token'];
        $this->active->update(['name' => 'Időközben átnevezett']);
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $token])->assertSessionHas('duplicate_warning', true);
        $this->assertDatabaseCount('partners', 2);
        $this->post('/partners', $data + ['confirm_duplicates' => 1, 'duplicate_token' => $this->check($data)['token']])->assertSessionMissing('duplicate_warning');
        $this->assertDatabaseCount('partners', 3);
    }

    public function test_removed_last_match_no_longer_requires_confirmation(): void
    {
        $data = ['name' => $this->active->name, 'type' => 'customer'];
        $this->active->delete();
        $this->post('/partners', $data)->assertRedirect()->assertSessionMissing('duplicate_warning');
        $this->assertDatabaseCount('partners', 2);
        $this->assertSame(1, Partner::where('name', $data['name'])->count());
    }

    public function test_unrelated_validation_error_keeps_valid_confirmation_and_entered_data(): void
    {
        $data = ['name' => $this->active->name, 'type' => 'customer', 'phone' => str_repeat('1', 21),
            'confirm_duplicates' => 1, 'duplicate_token' => $this->check(['name' => $this->active->name])['token']];
        $this->from(route('partners.create'))->post('/partners', $data)->assertRedirect(route('partners.create'))->assertSessionHasErrors('phone');
        $this->get(route('partners.create'))->assertOk()->assertViewHas('duplicateAcknowledged', true);
        $this->assertDatabaseCount('partners', 2);
        $data['phone'] = '1234';
        $this->post('/partners', $data)->assertRedirect()->assertSessionMissing('duplicate_warning');
        $this->assertDatabaseHas('partners', ['name' => $data['name'], 'phone' => '1234']);
    }

    public static function roles(): array { return [['admin'], ['manager'], ['worker']]; }

    #[DataProvider('roles')]
    public function test_allowed_roles_can_use_state_actions_and_duplicate_lookup(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->getJson(route('partners.duplicates', ['name' => $this->active->name]))->assertOk();
        $this->patch(route('partners.archive', $this->active))->assertRedirect();
        $this->patch(route('partners.restore', $this->active))->assertRedirect();
    }

    public function test_guests_unknown_roles_and_missing_records_are_handled_without_data_changes(): void
    {
        $this->patch('/partners/999999/archive')->assertNotFound();
        $this->patch('/partners/999999/restore')->assertNotFound();
        $this->getJson('/partners/999999/duplicates')->assertNotFound();
        $user = User::factory()->create(['role' => 'worker']); $user->role = 'unknown'; $this->actingAs($user);
        $this->getJson('/partners/duplicates')->assertForbidden();
        $this->patch(route('partners.archive', $this->active))->assertForbidden();
        $this->patch(route('partners.restore', $this->archived))->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/partners/duplicates')->assertUnauthorized();
        $this->patch(route('partners.archive', $this->active))->assertRedirect(route('login'));
        $this->patch(route('partners.restore', $this->archived))->assertRedirect(route('login'));
        $this->assertFalse($this->active->fresh()->isArchived());
        $this->assertTrue($this->archived->fresh()->isArchived());
    }
}
