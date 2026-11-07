<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class AppNavigation
{
    public static function groups(?User $user): array
    {
        $manage = in_array($user?->role, ['admin', 'manager'], true);
        $work = $manage || $user?->role === 'worker';
        $groups = [['label' => 'Áttekintés', 'items' => [
            ['route' => 'dashboard', 'label' => 'Vezérlőpult', 'icon' => 'grid', 'patterns' => ['dashboard', 'dashboard.*']],
        ]]];
        if ($work) {
            $groups[] = ['label' => 'Készletkezelés', 'items' => [
                ['route' => 'stock.index', 'label' => 'Készlet', 'icon' => 'layers', 'patterns' => ['stock.*']],
                ['route' => 'movements.index', 'label' => 'Készletmozgások', 'icon' => 'activity', 'patterns' => ['movements.*', 'quality-returns.*']],
                ['route' => 'transfers.index', 'label' => 'Átmozgatások', 'icon' => 'truck', 'patterns' => ['transfers.*', 'shipments.*']],
            ]];
            $groups[] = ['label' => 'Törzsadatok', 'items' => array_values(array_filter([
                ['route' => 'products.index', 'label' => 'Termékek', 'icon' => 'box', 'patterns' => ['products.*']],
                $manage ? ['route' => 'categories.index', 'label' => 'Kategóriák', 'icon' => 'folder', 'patterns' => ['categories.*']] : null,
                $manage ? ['route' => 'warehouses.index', 'label' => 'Raktárak', 'icon' => 'warehouse', 'patterns' => ['warehouses.*']] : null,
                ['route' => 'partners.index', 'label' => 'Partnerek', 'icon' => 'contacts', 'patterns' => ['partners.*']],
            ]))];
        }
        if ($manage) $groups[] = ['label' => 'Adminisztráció', 'items' => [
            ['route' => 'users.index', 'label' => 'Felhasználók', 'icon' => 'users', 'patterns' => ['users.*']],
        ]];
        return $groups;
    }

    public static function breadcrumbs(Request $request): array
    {
        $section = explode('.', $request->route()?->getName() ?? '')[0];
        [$label, $route, $create] = match ($section) {
            'products' => ['Termékek', 'products.index', 'Új termék'],
            'categories' => ['Kategóriák', 'categories.index', 'Új kategória'],
            'warehouses' => ['Raktárak', 'warehouses.index', 'Új raktár'],
            'partners' => ['Partnerek', 'partners.index', 'Új partner'],
            'stock' => ['Készlet', 'stock.index', null],
            'movements' => ['Készletmozgások', 'movements.index', 'Új készletmozgás'],
            'transfers' => ['Átmozgatások', 'transfers.index', 'Új átmozgatás'],
            'shipments' => ['Szállítmányok', 'shipments.index', 'Új rakodási terv'],
            'quality-returns' => ['Visszáru-minősítés', 'quality-returns.index', null],
            'users' => ['Felhasználók', 'users.index', 'Új felhasználó'],
            'profile' => ['Profil', 'profile.edit', null],
            'app-search' => ['Gyorskereső', 'app-search.index', null],
            default => ['Vezérlőpult', 'dashboard', null],
        };
        $detail = match (true) {
            $request->routeIs('dashboard.customize', 'dashboard.layout.update') => 'Testreszabás',
            $request->routeIs('movements.return.*') => 'Visszáru rögzítése',
            $request->routeIs('*.create') => $create,
            $request->routeIs('*.edit') && $section !== 'profile' => 'Szerkesztés',
            $request->routeIs('*.show') => 'Adatlap',
            default => null,
        };
        $items = [];
        if ($section === 'shipments') $items[] = ['label' => 'Átmozgatások', 'url' => route('transfers.index')];
        if ($section === 'quality-returns') $items[] = ['label' => 'Készletmozgások', 'url' => route('movements.index')];
        $items[] = ['label' => $label, 'url' => $detail ? route($route) : null];
        if ($detail) $items[] = ['label' => $detail, 'url' => null];
        return $items;
    }
}
