<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'worker']);
        $this->actingAs($this->actor);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(DashboardLayout::defaults(), ['version' => 0], $changes);
    }

    private function panels(string $html): array
    {
        preg_match_all('/data-dashboard-panel="([a-z_]+)"/', $html, $matches);
        return $matches[1];
    }

    public function test_default_dashboard_and_editor_keep_all_widgets_in_the_existing_order(): void
    {
        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame(array_keys(DashboardLayout::PANELS), $this->panels($response->getContent()));
        $this->get('/dashboard/customize')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Vezérlőpult testreszabása');
        $this->assertNull($this->actor->fresh()->dashboard_layout);
    }

    public function test_layout_order_and_visibility_persist_only_for_the_current_user_without_changing_profile_fields(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $before = $this->actor->only(['name', 'email', 'role', 'password']);
        $order = array_reverse(array_keys(DashboardLayout::PANELS));
        $visible = ['activity', 'product_alerts'];
        $this->patchJson('/dashboard/layout', $this->payload(['order' => $order, 'visible' => $visible, 'user_id' => $other->id, 'role' => 'admin']))
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('layout.order', $order);
        $this->assertSame($before, $this->actor->fresh()->only(array_keys($before)));
        $this->assertNull($other->fresh()->dashboard_layout);
        $this->actor->refresh();
        $response = $this->get('/dashboard')->assertOk();
        $this->assertSame(['activity', 'product_alerts'], $this->panels($response->getContent()));
        $this->assertSame(1, $this->actor->dashboard_layout_version);
        $this->actingAs($other);
        $this->assertSame(array_keys(DashboardLayout::PANELS), $this->panels($this->get('/dashboard')->assertOk()->getContent()));
    }

    public function test_stale_and_replayed_versions_cannot_overwrite_a_later_save(): void
    {
        $this->patchJson('/dashboard/layout', $this->payload(['visible' => ['activity']]))->assertOk();
        $this->patchJson('/dashboard/layout', $this->payload(['visible' => ['tasks']]))->assertConflict();
        $this->assertSame(['activity'], $this->actor->fresh()->dashboard_layout['visible']);
        $this->patch('/dashboard/layout', $this->payload(['visible' => ['tasks']]))->assertConflict()
            ->assertSee('Mentett elrendezés betöltése')->assertSee('nem írták felül');
        $this->assertSame(1, $this->actor->fresh()->dashboard_layout_version);
        $this->patchJson('/dashboard/layout', $this->payload(['version' => 1, 'visible' => ['tasks']]))->assertOk()->assertJsonPath('version', 2);
    }

    public function test_all_hidden_has_a_recovery_action_and_reset_restores_the_default_dashboard(): void
    {
        $data = $this->payload(); unset($data['visible']);
        $this->patch('/dashboard/layout', $data)->assertRedirect(route('dashboard'));
        $this->actor->refresh();
        $response = $this->get('/dashboard')->assertOk()->assertSee('Jelenleg minden blokk el van rejtve')->assertSee('Blokkok kiválasztása');
        $this->assertSame([], $this->panels($response->getContent()));
        $this->patch('/dashboard/layout', $this->payload(['version' => 1, 'visible' => [], 'reset' => '1']))->assertRedirect(route('dashboard.customize').'#layout-form-title');
        $this->actor->refresh();
        $this->assertSame(DashboardLayout::defaults(), $this->actor->dashboard_layout);
        $this->assertSame(array_keys(DashboardLayout::PANELS), $this->panels($this->get('/dashboard')->assertOk()->getContent()));
    }

    public function test_native_move_and_save_preserve_the_chart_and_only_visible_panel_pagination(): void
    {
        $this->patch('/dashboard/layout', $this->payload(['move' => 'up:activity', 'days' => 30, 'chart' => 'line', 'movement_page' => 2]))
            ->assertRedirect(route('dashboard.customize', ['days' => 30, 'chart' => 'line', 'movement_page' => 2]).'#layout-activity');
        $this->assertSame('activity', $this->actor->fresh()->dashboard_layout['order'][5]);
        $this->patchJson('/dashboard/layout', $this->payload(['version' => 1, 'visible' => ['activity'], 'days' => 30, 'chart' => 'line', 'movement_page' => 2, 'return_to' => 'https://example.com']))
            ->assertOk()->assertJsonPath('redirect', route('dashboard', ['days' => 30, 'chart' => 'line']));
        $this->actor->refresh();
        $this->get('/dashboard?days=30&chart=line&movement_page=999')->assertRedirect(route('dashboard', ['days' => '30', 'chart' => 'line']));
        $this->get('/dashboard/customize?days[]=30&chart[]=line&movement_page[]=2')->assertOk();
    }

    public function test_hidden_category_alert_card_links_to_settings_instead_of_an_absent_anchor(): void
    {
        $this->actor->forceFill(['dashboard_layout' => $this->payload(['visible' => ['tasks']])])->save();
        $this->get('/dashboard')->assertOk()->assertDontSee('href="#category-alerts"', false)->assertSee('#layout-category_alerts');
    }

    public static function invalidPayloads(): array
    {
        return [
            ['version', -1], ['version', 'bad'], ['version', ['0']], ['version', 2147483648],
            ['order', []], ['order', ['activity']], ['order', array_fill(0, 7, 'activity')],
            ['order', ['tasks', 'stock_summary', 'product_alerts', 'category_alerts', 'pending_transfers', 'recent_movements', '../../users']],
            ['order', ['a' => 'tasks', 'b' => 'stock_summary', 'c' => 'product_alerts', 'd' => 'category_alerts', 'e' => 'pending_transfers', 'f' => 'recent_movements', 'g' => 'activity']],
            ['order', 'activity'], ['visible', 'activity'], ['visible', ['activity', 'activity']], ['visible', [['activity']]], ['visible', ['bad']],
            ['move', 'up:bad'], ['move', ['up:activity']], ['reset', 'bad'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_layout_input_is_rejected_without_writing(string $key, mixed $value): void
    {
        $this->patchJson('/dashboard/layout', $this->payload([$key => $value]))->assertUnprocessable();
        $this->assertNull($this->actor->fresh()->dashboard_layout);
        $this->assertSame(0, $this->actor->fresh()->dashboard_layout_version);
    }

    public function test_validation_preserves_unchecked_boxes_and_draft_order_for_correction(): void
    {
        $this->from('/dashboard/customize')->patch('/dashboard/layout', $this->payload(['_layout_form' => '1', 'visible' => [], 'move' => 'bad']))
            ->assertRedirect('/dashboard/customize')->assertSessionHasErrors('move');
        $this->get('/dashboard/customize')->assertOk()->assertViewHas('layout', fn ($layout) => $layout['visible'] === []);
    }

    public function test_old_or_corrupt_preferences_fall_back_to_known_panels_and_new_panels_are_appended(): void
    {
        $this->assertSame(DashboardLayout::defaults(), DashboardLayout::normalize('bad'));
        $layout = DashboardLayout::normalize(['order' => ['activity', 'activity', '<script>'], 'visible' => []]);
        $this->assertSame('activity', $layout['order'][0]);
        $this->assertNotContains('activity', $layout['visible']);
        $this->assertCount(7, $layout['order']);
        $this->assertCount(6, $layout['visible']);
    }

    public function test_guests_cannot_read_or_write_preferences(): void
    {
        auth()->logout();
        $this->get('/dashboard/customize')->assertRedirect(route('login'));
        $this->patchJson('/dashboard/layout', $this->payload())->assertUnauthorized();
    }

    public function test_hidden_pager_cleanup_does_not_swallow_validation_feedback(): void
    {
        $this->actor->forceFill(['dashboard_layout' => $this->payload(['visible' => ['activity']])])->save();
        $this->followingRedirects()->get('/dashboard?days=bad&movement_page=2')->assertOk()
            ->assertSee('Az időszak nem érvényes.');
    }

    public function test_invalid_partial_order_keeps_valid_unchecked_choices_in_the_draft(): void
    {
        $this->from('/dashboard/customize')->patch('/dashboard/layout', $this->payload(['_layout_form' => '1', 'order' => ['activity'], 'visible' => []]))
            ->assertRedirect('/dashboard/customize')->assertSessionHasErrors('order');
        $this->get('/dashboard/customize')->assertOk()->assertViewHas('layout', fn ($layout) => $layout['visible'] === [] && $layout['order'][0] === 'activity');
        $this->assertNull($this->actor->fresh()->dashboard_layout);
    }

    public function test_unrelated_old_form_input_cannot_reorder_the_layout_editor(): void
    {
        $this->withSession(['_old_input' => ['order' => ['activity'], 'visible' => [], 'version' => 99]])
            ->get('/dashboard/customize')->assertOk()->assertViewHas('layout', DashboardLayout::defaults())
            ->assertViewHas('layoutVersion', 0);
    }

    public function test_overflow_pagination_preserves_save_confirmation_and_filter_errors(): void
    {
        $this->withSession(['success' => 'A vezérlőpult elrendezése mentve.']);
        $this->followingRedirects()->get('/dashboard?days=bad&movement_page=999')->assertOk()
            ->assertSee('Az időszak nem érvényes.')->assertSee('A vezérlőpult elrendezése mentve.');
    }

    public function test_invalid_revision_cannot_be_silently_replaced_with_a_fresh_revision_for_an_old_draft(): void
    {
        $this->patchJson('/dashboard/layout', $this->payload(['visible' => ['activity']]))->assertOk();
        $this->actor->refresh();
        $this->from('/dashboard/customize')->patch('/dashboard/layout', $this->payload(['_layout_form' => '1', 'version' => 'bad', 'visible' => ['tasks']]))
            ->assertRedirect('/dashboard/customize')->assertSessionHasErrors('version');
        $this->get('/dashboard/customize')->assertOk()->assertViewHas('conflict', true)->assertViewHas('reloadReason', 'invalid_version')
            ->assertSee('A mentés verzióadata hiányos vagy hibás.');
        $this->assertSame(['activity'], $this->actor->fresh()->dashboard_layout['visible']);
    }
}
