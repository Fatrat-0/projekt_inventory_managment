<?php

namespace Tests\Feature;

use App\Models\{Partner, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerContactEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'worker']));
    }

    public static function blankContacts(): array
    {
        $cases = [];
        foreach (['null' => null, 'empty' => '', 'spaces' => '   ', 'tabs and newlines' => "\t\r\n", 'nonbreaking space' => "\u{00A0}",
            'thin space' => "\u{2009}", 'zero width space' => "\u{200B}", 'bom' => "\u{FEFF}", 'mixed' => " \t\u{00A0}\u{200B}\n",
            'nul' => "\0", 'nul mixed' => "\0 \t\u{FEFF}\0"] as $label => $value) {
            foreach (['email', 'phone'] as $field) $cases[$field.' '.$label] = [$field, $value];
        }
        return $cases;
    }

    #[DataProvider('blankContacts')]
    public function test_legacy_blank_contacts_agree_in_filters_sorting_and_display_without_modifying_records(string $field, ?string $value): void
    {
        $missing = Partner::create(['name' => 'A hiányos', 'type' => 'both', 'email' => 'legacy@example.test', 'phone' => '123', $field => $value]);
        $complete = Partner::create(['name' => 'Z teljes', 'type' => 'both', 'email' => 'complete@example.test', 'phone' => '0']);
        $before = Partner::orderBy('id')->get()->toArray();
        $this->get('/partners?contact=complete')->assertOk()->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$complete->id]);
        $this->get('/partners?contact=missing')->assertOk()->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$missing->id]);
        foreach (['asc', 'desc'] as $direction) {
            $this->get('/partners?sort_by='.$field.'&sort_dir='.$direction)->assertOk()
                ->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$complete->id, $missing->id]);
        }
        $html = view('partners.contact', ['partner' => $missing, 'field' => $field])->render();
        $this->assertStringContainsString('Nincs megadva', $html);
        $this->assertSame($before, Partner::orderBy('id')->get()->toArray());
    }

    public function test_both_contact_filters_partition_active_and_archived_dual_role_partners(): void
    {
        $complete = Partner::create(['name' => 'Teljes', 'type' => 'both', 'email' => 'complete@example.test', 'phone' => '0']);
        $missing = Partner::create(['name' => 'Hiányos', 'type' => 'both', 'email' => null, 'phone' => '123']);
        $archived = Partner::create(['name' => 'Régi', 'type' => 'supplier', 'email' => 'old@example.test', 'phone' => null]);
        $archived->forceFill(['archived_at' => now()])->save();
        foreach (['customer', 'supplier'] as $type) {
            $this->get('/partners?type='.$type.'&contact=complete')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$complete->id]);
            $this->get('/partners?type='.$type.'&contact=missing')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$missing->id]);
        }
        $this->get('/partners?status=all&contact=missing&type=supplier')->assertViewHas('partners', fn ($p) => $p->pluck('id')->sort()->values()->all() === [$missing->id, $archived->id]);
        $this->get('/partners?status=archived&contact=complete')->assertViewHas('partners', fn ($p) => $p->isEmpty());
    }

    public function test_current_form_normalizes_invisible_contact_values_but_retains_the_zero_phone(): void
    {
        $this->post('/partners', ['name' => 'Üres kontakt', 'type' => 'both', 'email' => "\u{00A0}\u{200B}", 'phone' => "\t\u{2009}"])->assertSessionHasNoErrors();
        $partner = Partner::where('name', 'Üres kontakt')->sole();
        $this->assertNull($partner->email);
        $this->assertNull($partner->phone);
        $this->put(route('partners.update', $partner), ['lock_version' => $partner->fresh()->lock_version, 'name' => $partner->name, 'type' => 'both', 'email' => 'real@example.test', 'phone' => '0'])->assertSessionHasNoErrors();
        $this->get('/partners?contact=complete')->assertViewHas('partners', fn ($p) => $p->pluck('id')->all() === [$partner->id]);
    }

    public function test_duplicate_detection_also_trims_invisible_characters_in_legacy_email(): void
    {
        $partner = Partner::create(['name' => 'Régi e-mail', 'type' => 'both', 'email' => "\u{00A0}Office@Example.test\u{200B}"]);
        $this->getJson(route('partners.duplicates', ['email' => 'office@example.test']))->assertOk()->assertJsonPath('matches.0.id', $partner->id)->assertJsonPath('matches.0.same_email', true);
        $html = view('partners.contact', ['partner' => $partner, 'field' => 'email'])->render();
        $this->assertStringContainsString('mailto:Office%40Example.test', $html);
        $this->assertStringNotContainsString("\u{200B}", $html);
    }
}
