<?php

namespace App\Support;

use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;

class WarehouseStatisticsReport
{
    public const CAPACITY_NOTE = 'A kapacitás az aktív tárhelyek összesített adata. A lefoglalt részhez már célhelyre rendelt, úton lévő tételek tartoznak.';

    public readonly array $charts;
    public readonly array $counters;

    public function __construct(Collection $rows, bool $singleWarehouse)
    {
        $assigned = (int) $rows->sum('assigned_units');
        $quarantined = (int) $rows->sum('quarantined_units');
        $stock = [$assigned, max(0, (int) $rows->sum('physical_units') - $assigned - $quarantined), $quarantined];

        $this->charts = [
            'weight' => self::capacity($rows, $singleWarehouse, 'weight', 'Terhelési kapacitás', 'kg',
                'Aktív tárhelyek teherbírása', 'Nincs aktív tárhelyhez beállított terhelési kapacitás.'),
            'pallets' => self::capacity($rows, $singleWarehouse, 'slots', 'Raklapférőhely', 'hely',
                'Aktív raklaphelyek férőhelyei', 'Nincs aktív raklaphely.'),
            'stock' => [
                'title' => 'Helyi készlet összetétele', 'subtitle' => 'Megoszlás tárolási állapot szerint',
                'parts' => $stock, 'total' => array_sum($stock), 'unit' => 'db', 'empty' => 'Nincs fizikai készlet.',
                'value' => self::format(array_sum($stock), 'db'), 'valueLabel' => 'Fizikai készlet',
                'legend' => ['Tárhelyen', 'Tárhely nélkül kiadható', 'Elkülönítve'], 'colors' => ['#4f46e5', '#0ea5e9', '#f59e0b'],
            ],
        ];
        $this->counters = collect(['location_count' => 'Aktív tárhely', 'incoming_count' => 'Beérkező átmozgatási tétel', 'outgoing_count' => 'Kimenő átmozgatási tétel'])
            ->map(fn ($label, $key) => ['label' => $label, 'value' => number_format($rows->sum($key), 0, ',', ' ')])->all();
    }

    private static function capacity(Collection $rows, bool $singleWarehouse, string $metric, string $title, string $unit, string $subtitle, string $empty): array
    {
        $used = (int) $rows->sum('occupied_'.$metric);
        $reserved = (int) $rows->sum('reserved_'.$metric);
        $limit = 'max_'.$metric;
        $parts = [$used, $reserved, max(0, (int) $rows->sum($limit) - $used - $reserved)];
        $total = array_sum($parts);
        $share = $total > 0 ? 100 * ($used + $reserved) / $total : 0;

        return [
            'title' => $title, 'unit' => $unit, 'empty' => $empty, 'parts' => $parts, 'total' => $total,
            'subtitle' => $singleWarehouse ? $subtitle : $rows->where($limit, '>', 0)->count().' / '.$rows->count().' raktár adatai',
            'value' => rtrim(rtrim(number_format($share, 1, ',', ''), '0'), ',').'%', 'valueLabel' => 'Használt + lefoglalt',
            'legend' => ['Használt', 'Lefoglalt', 'Szabad'], 'colors' => ['#4f46e5', '#f59e0b', '#10b981'],
        ];
    }

    public static function format(int $value, string $unit): string
    {
        return ($unit === 'kg' ? WarehouseLocation::formatWeight($value) : number_format($value, 0, ',', ' ')).' '.$unit;
    }
}
