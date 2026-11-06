<?php

namespace App\Support;

use App\Queries\DashboardOverview;
use Illuminate\Http\Request;

class DashboardLayout
{
    public const PANELS = [
        'tasks' => ['label' => 'Feladatösszesítő', 'view' => 'dashboard.tasks', 'wide' => true],
        'stock_summary' => ['label' => 'Készletösszesítő', 'view' => 'dashboard.stock-summary', 'wide' => true],
        'product_alerts' => ['label' => 'Termékhiányok', 'view' => 'dashboard.product-alerts', 'wide' => false],
        'category_alerts' => ['label' => 'Kategóriahiányok', 'view' => 'dashboard.category-alerts', 'wide' => false],
        'pending_transfers' => ['label' => 'Átvételre váró átmozgatások', 'view' => 'dashboard.pending-transfers', 'wide' => false],
        'recent_movements' => ['label' => 'Legutóbbi készletmozgások', 'view' => 'dashboard.recent-movements', 'wide' => false],
        'activity' => ['label' => 'Készletmozgások diagramja', 'view' => 'dashboard.activity', 'wide' => true],
    ];
    public const PAGE_PANELS = ['product_alert_page' => 'product_alerts', 'category_alert_page' => 'category_alerts', 'transfer_page' => 'pending_transfers', 'movement_page' => 'recent_movements'];

    public static function defaults(): array
    {
        return ['order' => array_keys(self::PANELS), 'visible' => array_keys(self::PANELS)];
    }

    public static function normalize(mixed $stored): array
    {
        if (!is_array($stored) || !is_array($stored['order'] ?? null) || !is_array($stored['visible'] ?? null)) return self::defaults();
        $order = self::knownIds($stored['order']);
        $new = array_values(array_diff(array_keys(self::PANELS), $order));
        return ['order' => array_merge($order, $new), 'visible' => array_values(array_unique(array_merge(self::knownIds($stored['visible']), $new)))];
    }

    public static function draft(mixed $order, mixed $visible, array $stored): array
    {
        // Repair invalid order independently: a validation failure must not re-enable unchecked panels.
        $normalized = self::normalize(['order' => is_array($order) ? $order : $stored['order'], 'visible' => []]);
        return ['order' => $normalized['order'], 'visible' => is_array($visible) ? self::knownIds($visible) : $stored['visible']];
    }

    private static function knownIds(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && isset(self::PANELS[$id]))));
    }

    public static function context(Request $request, ?array $visible = null): array
    {
        $params = [];
        if (in_array($request->input('days'), [7, '7', 30, '30'], true)) $params['days'] = (int)$request->input('days');
        if (in_array($request->input('chart'), ['bar', 'line'], true)) $params['chart'] = $request->input('chart');
        foreach (array_keys(DashboardOverview::PANEL_PAGES) as $key) {
            $page = filter_var($request->input($key), FILTER_VALIDATE_INT);
            if ($page !== false && $page >= 1 && $page <= 2147483647 && ($visible === null || in_array(self::PAGE_PANELS[$key], $visible, true))) $params[$key] = $page;
        }
        return $params;
    }
}
