<?php

namespace Tests\Feature;

use App\Models\{User, Warehouse};
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

    public static function roles(): array { return [['admin', 10], ['manager', 8], ['worker', 6]]; }

    #[DataProvider('roles')]
    public function test_navigation_only_links_to_pages_the_role_can_open(string $role, int $count): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $response = $this->get('/dashboard')->assertOk();
        $xpath = $this->document($response->getContent());
        $links = $xpath->query("//nav[@aria-label='Főmenü']//a");
        $this->assertCount($count, $links);
        $this->assertCount($count, $xpath->query("//nav[@aria-label='Mobil főmenü']//a"));
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href === route('warehouse-tasks.index')) {
                $this->get($href)->assertRedirectToRoute('warehouse-picking-pages.index');
            } else {
                $this->get($href)->assertOk();
            }
        }
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
            ['/raktari-feladatok/kitarolas', 'warehouse-tasks.index', 'Kitárolás'],
            ['/raktari-feladatok/atvetel', 'warehouse-tasks.index', 'Átvétel'],
            ['/raktari-feladatok/betarolas', 'warehouse-tasks.index', 'Betárolás'],
            ['/raktari-feladatok/athelyezes', 'warehouses.index', 'Áthelyezési előzmények'],
            ['/raktari-feladatok/athelyezes/uj', 'warehouses.index', 'Új áthelyezés'],
            ['/atmozgatasok', 'transfer-intent-pages.index', 'Átmozgatások'],
            ['/atmozgatasok/uj-igeny', 'transfer-intent-pages.index', 'Új átmozgatási igény'],
            ['/transfers/create', 'transfer-intent-pages.index', 'Új átmozgatás'],
            ['/shipments', 'transfer-intent-pages.index', 'Fuvarok és szállítmányok'],
            ['/shipments/create', 'transfer-intent-pages.index', 'Új fuvar tervezése'],
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
        $topbarNavigation = "//header[@data-app-topbar]//nav";
        $transferModule = $active === 'transfer-intent-pages.index';
        $hasTopbarNavigation = $transferModule || $active === 'warehouse-tasks.index'
            || in_array($path, ['/quality-returns', '/movements/create'], true);
        $this->assertCount($hasTopbarNavigation ? 1 : 0, $xpath->query($topbarNavigation));
        if ($transferModule) {
            $tabs = $topbarNavigation."[@aria-label='Átmozgatások modul']";
            $this->assertCount(1, $xpath->query($tabs));
            $current = $xpath->query($tabs."//a[@aria-current='page']");
            $this->assertCount(1, $current);
            $route = str_starts_with($path, '/shipments') ? 'shipments.index' : 'transfer-intent-pages.index';
            $this->assertSame(route($route), $current->item(0)->getAttribute('href'));
        }
        if ($path === '/quality-returns') {
            $current = $xpath->query($topbarNavigation."//a[@aria-current='page']");
            $this->assertCount(1, $current);
            $this->assertSame(route('quality-returns.index'), $current->item(0)->getAttribute('href'));
        }
        $this->assertCount(1, $xpath->query('//main//h1'));
        $this->assertStringContainsString($label, $xpath->query('//title')->item(0)->textContent);
        foreach ($xpath->query('//main//a[@data-parent-navigation]') as $link) $this->get($link->getAttribute('href'))->assertOk();
    }

    public function test_warehouse_task_home_and_tabs_follow_existing_warehouse_permissions(): void
    {
        $warehouse = Warehouse::factory()->create();
        $home = route('warehouse-tasks.index');
        $this->get($home)->assertRedirect(route('login'));
        $desktop = "//nav[@aria-label='Főmenü']";
        $tabs = "//header[@data-app-topbar]//nav[@data-warehouse-task-tabs]//a";
        $menuEntry = $desktop."//a[@href='".$home."']";

        foreach ([
            'requester' => [null, []],
            'dispatch_operator' => ['warehouse-picking-pages.index', ['Kitárolás']],
            'receiving_operator' => ['warehouse-receiving-pages.index', ['Átvétel', 'Betárolás']],
            'supervisor' => ['warehouse-picking-pages.index', ['Kitárolás', 'Átvétel', 'Betárolás']],
        ] as $role => [$target, $labels]) {
            $member = User::factory()->create(['role' => 'worker']);
            $member->addWarehouseMembership($warehouse->getKey(), [$role]);
            $this->actingAs($member);
            $dashboard = $this->document($this->get('/dashboard')->assertOk()->getContent());
            $this->assertCount($target ? 1 : 0, $dashboard->query($menuEntry));
            if ($target === null) {
                $this->get($home)->assertForbidden();
                continue;
            }

            $this->get($home)->assertRedirectToRoute($target);
            $page = $this->document($this->get(route($target))->assertOk()->getContent());
            $actual = [];
            foreach ($page->query($tabs) as $tab) $actual[] = $tab->getAttribute('aria-label');
            $this->assertSame($labels, $actual);
            $this->assertCount(0, $page->query($tabs."[@aria-label='Áthelyezés']"));
            $this->assertCount(1, $page->query($menuEntry."[@aria-current='page']"));
            $this->assertCount(1, $page->query($tabs."[@aria-current='page']"));
            $this->assertCount(0, $page->query("//nav[@aria-label='Átmozgatások modul']"));
        }
    }

    public function test_previous_task_urls_keep_ids_and_query_and_cannot_bypass_permissions(): void
    {
        $legacy = '/atmozgatasok/raktari-feladatok';
        $canonical = '/raktari-feladatok';
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            '' => '/kitarolas',
            '/42' => '/kitarolas/42',
            '/42/alaprajz/22' => '/kitarolas/42/alaprajz/22',
            '/atvetel' => '/atvetel',
            '/atvetel/42/7' => '/atvetel/42/7',
            '/betarolas' => '/betarolas',
            '/betarolas/42' => '/betarolas/42',
            '/betarolas/42/alaprajz/22' => '/betarolas/42/alaprajz/22',
            '/betarolas/kezi/42' => '/betarolas/kezi/42',
            '/betarolas/kezi/42/alaprajz/22' => '/betarolas/kezi/42/alaprajz/22',
        ] as $path => $target) {
            $query = '?origin=manual&per_page=25&task=4';
            $this->get($legacy.$path.$query)->assertRedirect($canonical.$target.$query);
        }
        $this->getJson($legacy.'/szamlalok')->assertOk()->assertJsonPath('counts.putaway', 0);
        $this->get($legacy.'/ismeretlen')->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'worker']));
        foreach (['' => '/kitarolas', '/atvetel' => '/atvetel', '/betarolas' => '/betarolas'] as $path => $target) {
            $this->get($canonical.$target)->assertForbidden();
            $this->followingRedirects()->get($legacy.$path)->assertForbidden();
        }
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
