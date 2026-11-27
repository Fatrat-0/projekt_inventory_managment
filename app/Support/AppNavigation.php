<?php

namespace App\Support;

use App\Models\{Shipment, TransferIntent, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppNavigation
{
    public const WAREHOUSE_TASK_TABS = [
        'picking' => ['warehouse-picking-pages', 'Kitárolás', 'tray-out'],
        'receiving' => ['warehouse-receiving-pages', 'Átvétel', 'tray-in'],
        'putaway' => ['warehouse-putaway-pages', 'Betárolás', 'layers'],
    ];

    public static function warehouseTaskHome(User $user): ?string
    {
        $gate = Gate::forUser($user);
        if ($gate->allows('viewPickingTaskList', TransferIntent::class)) return 'warehouse-picking-pages.index';
        if ($gate->allows('viewAnyReceiving', Shipment::class)) return 'warehouse-receiving-pages.index';

        return null;
    }

    public static function groups(?User $user): array
    {
        $manage = in_array($user?->role, ['admin', 'manager'], true);
        $work = $manage || $user?->role === 'worker';
        $groups = [['label' => 'Áttekintés', 'items' => [
            ['route' => 'dashboard', 'label' => 'Vezérlőpult', 'icon' => 'grid', 'patterns' => ['dashboard', 'dashboard.*']],
        ]]];
        if ($work) {
            $groups[] = ['label' => 'Készletkezelés', 'items' => array_values(array_filter([
                ['route' => 'stock.index', 'label' => 'Készlet', 'icon' => 'layers', 'patterns' => $manage ? ['stock.*'] : ['stock.*', 'warehouses.locations.*', 'warehouses.floor-plan.*', 'warehouses.storage-systems.*', 'warehouses.relocations.*', 'warehouse-relocation-pages.*']],
                ['route' => 'movements.index', 'label' => 'Készletmozgások', 'icon' => 'activity', 'patterns' => ['movements.*', 'quality-returns.*']],
                self::warehouseTaskHome($user) !== null
                    ? ['route' => 'warehouse-tasks.index', 'label' => 'Raktári feladatok', 'icon' => 'tasks', 'patterns' => array_map(
                        fn ($section) => $section.'.*', array_column(self::WAREHOUSE_TASK_TABS, 0))]
                    : null,
                ['route' => 'transfer-intent-pages.index', 'label' => 'Átmozgatások', 'icon' => 'truck', 'patterns' => ['transfer-intent-pages.*', 'transfers.*', 'shipments.*', 'vehicle-pages.*']],
            ]))];
            $groups[] = ['label' => 'Törzsadatok', 'items' => array_values(array_filter([
                ['route' => 'products.index', 'label' => 'Termékek', 'icon' => 'box', 'patterns' => ['products.*']],
                $manage ? ['route' => 'categories.index', 'label' => 'Kategóriák', 'icon' => 'folder', 'patterns' => ['categories.*']] : null,
                $manage ? ['route' => 'warehouses.index', 'label' => 'Raktárak', 'icon' => 'warehouse', 'patterns' => ['warehouses.*', 'warehouse-relocation-pages.*']] : null,
                ['route' => 'partners.index', 'label' => 'Partnerek', 'icon' => 'contacts', 'patterns' => ['partners.*']],
            ]))];
        }
        if ($user?->role === 'admin') $groups[] = ['label' => 'Adminisztráció', 'items' => [
            ['route' => 'users.index', 'label' => 'Felhasználók', 'icon' => 'users', 'patterns' => ['users.*']],
        ]];
        return $groups;
    }

    public static function breadcrumbs(Request $request): array
    {
        $section = explode('.', $request->route()?->getName() ?? '')[0];
        if ($request->routeIs('warehouses.relocations.*', 'warehouse-relocation-pages.*')) {
            $manage = in_array($request->user()?->role, ['admin', 'manager'], true);
            $items = [['label' => $manage ? 'Raktárak' : 'Készlet', 'url' => route($manage ? 'warehouses.index' : 'stock.index')]];
            $warehouse = $request->route('warehouse');
            $index = $warehouse ? route('warehouses.relocations.index', $warehouse) : route('warehouse-relocation-pages.index');
            $items[] = ['label' => 'Áthelyezési előzmények', 'url' => $request->routeIs('*.index') ? null : $index];
            if (!$request->routeIs('*.index')) {
                $items[] = ['label' => match (true) {
                    $request->routeIs('*.create') => 'Új áthelyezés',
                    $request->routeIs('*.plan') => 'Alaprajz',
                    default => 'Adatlap',
                }, 'url' => null];
            }

            return $items;
        }
        [$label, $route, $create] = match ($section) {
            'products' => ['Termékek', 'products.index', 'Új termék'],
            'categories' => ['Kategóriák', 'categories.index', 'Új kategória'],
            'warehouses' => ['Raktárak', 'warehouses.index', 'Új raktár'],
            'partners' => ['Partnerek', 'partners.index', 'Új partner'],
            'stock' => ['Készlet', 'stock.index', null],
            'movements' => ['Készletmozgások', 'movements.index', 'Új készletmozgás'],
            'transfer-intent-pages' => ['Átmozgatások', 'transfer-intent-pages.index', 'Új átmozgatási igény'],
            'warehouse-picking-pages' => ['Kitárolás', 'warehouse-picking-pages.index', null],
            'warehouse-receiving-pages' => ['Átvétel', 'warehouse-receiving-pages.index', null],
            'warehouse-putaway-pages' => ['Betárolás', 'warehouse-putaway-pages.index', null],
            'transfers' => ['Átmozgatások', 'transfers.index', 'Új átmozgatás'],
            'shipments' => ['Fuvarok és szállítmányok', 'shipments.index', 'Új fuvar tervezése'],
            'vehicle-pages' => ['Járművek', 'vehicle-pages.index', null],
            'quality-returns' => ['Visszáru-minősítés', 'quality-returns.index', null],
            'users' => ['Felhasználók', 'users.index', 'Új felhasználó'],
            'profile' => ['Profil', 'profile.edit', null],
            'app-search' => ['Gyorskereső', 'app-search.index', null],
            default => ['Vezérlőpult', 'dashboard', null],
        };
        $detail = match (true) {
            $request->routeIs('dashboard.customize', 'dashboard.layout.update') => 'Testreszabás',
            $request->routeIs('warehouses.statistics') => 'Statisztika',
            $request->routeIs('warehouses.floor-plan.*') => 'Tervrajz',
            $request->routeIs('warehouse-picking-pages.plan', 'warehouse-putaway-pages.plan', 'warehouse-putaway-pages.manual-plan') => 'Alaprajz',
            $request->routeIs('warehouse-picking-pages.show') => 'Kitárolási feladat',
            $request->routeIs('warehouse-receiving-pages.show') => 'Átvételi munkalap',
            $request->routeIs('warehouse-putaway-pages.show', 'warehouse-putaway-pages.manual') => 'Betárolási munkalap',
            $request->routeIs('warehouses.storage-systems.*') => 'Tárolórendszer',
            $request->routeIs('movements.return.*') => 'Visszáru rögzítése',
            $request->routeIs('*.create') => $create,
            $request->routeIs('*.edit') && $section !== 'profile' => 'Szerkesztés',
            $request->routeIs('*.show') => 'Adatlap',
            default => null,
        };
        $items = [];
        if (in_array($section, array_column(self::WAREHOUSE_TASK_TABS, 0), true)) {
            $items[] = ['label' => 'Raktári feladatok', 'url' => route('warehouse-tasks.index')];
        }
        if (in_array($section, ['shipments', 'vehicle-pages'], true)) $items[] = ['label' => 'Átmozgatások', 'url' => route('transfer-intent-pages.index')];
        if ($section === 'quality-returns') $items[] = ['label' => 'Készletmozgások', 'url' => route('movements.index')];
        $items[] = ['label' => $label, 'url' => $detail ? route($route) : null];
        if ($detail) $items[] = ['label' => $detail, 'url' => null];
        return $items;
    }
}
