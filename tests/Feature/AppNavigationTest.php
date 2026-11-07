<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($document);
    }

    public static function roles(): array { return [['admin', 9], ['manager', 9], ['worker', 6]]; }

    #[DataProvider('roles')]
    public function test_navigation_only_links_to_pages_the_role_can_open(string $role, int $count): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $response = $this->get('/dashboard')->assertOk();
        $xpath = $this->document($response->getContent());
        $links = $xpath->query("//nav[@aria-label='Főmenü']//a");
        $this->assertCount($count, $links);
        $this->assertCount($count, $xpath->query("//nav[@aria-label='Mobil főmenü']//a"));
        foreach ($links as $link) $this->get($link->getAttribute('href'))->assertOk();
        if ($role === 'worker') {
            foreach (['categories', 'warehouses', 'users'] as $path) {
                $this->assertCount(0, $xpath->query("//nav[@aria-label='Főmenü']//a[@href='".route($path.'.index')."']"));
                $this->get('/'.$path)->assertForbidden();
            }
        }
    }

    public static function pages(): array
    {
        return [
            ['/dashboard', 'dashboard', 'Vezérlőpult'],
            ['/products', 'products.index', 'Termékek'],
            ['/products/create', 'products.index', 'Új termék'],
            ['/categories/create', 'categories.index', 'Új kategória'],
            ['/warehouses/create', 'warehouses.index', 'Új raktár'],
            ['/partners/create', 'partners.index', 'Új partner'],
            ['/stock', 'stock.index', 'Készlet'],
            ['/movements/create', 'movements.index', 'Új készletmozgás'],
            ['/quality-returns', 'movements.index', 'Visszáru-minősítés'],
            ['/transfers/create', 'transfers.index', 'Új átmozgatás'],
            ['/shipments', 'transfers.index', 'Szállítmányok'],
            ['/shipments/create', 'transfers.index', 'Új rakodási terv'],
            ['/users/create', 'users.index', 'Új felhasználó'],
            ['/profile', null, 'Profil'],
        ];
    }

    #[DataProvider('pages')]
    public function test_active_item_and_document_title_follow_subpages_without_repeating_the_page_heading(string $path, ?string $active, string $label): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->get($path)->assertOk();
        $xpath = $this->document($response->getContent());
        $selected = $xpath->query("//nav[@aria-label='Főmenü']//a[@aria-current='page']");
        $this->assertCount($active ? 1 : 0, $selected);
        if ($active) $this->assertSame(route($active), $selected->item(0)->getAttribute('href'));
        $this->assertCount(0, $xpath->query("//header[@data-app-topbar]//nav"));
        $this->assertCount(1, $xpath->query('//main//h1'));
        $this->assertStringContainsString($label, $xpath->query('//title')->item(0)->textContent);
        foreach ($xpath->query('//main//a[@data-parent-navigation]') as $link) $this->get($link->getAttribute('href'))->assertOk();
    }

    public function test_user_content_is_escaped_and_logout_remains_a_csrf_protected_post(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'worker', 'name' => '<script>alert(1)</script>']));
        $response = $this->get('/dashboard')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $xpath = $this->document($response->getContent());
        $form = $xpath->query("//details[@data-account-menu]//form[@method='POST']")->item(0);
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertCount(1, $xpath->query(".//input[@name='_token']", $form));
        $this->assertCount(0, $xpath->query("//a[@href='".route('logout')."']"));
    }
}
