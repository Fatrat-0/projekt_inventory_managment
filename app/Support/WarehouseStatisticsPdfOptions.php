<?php

namespace App\Support;

use App\Queries\WarehouseStatistics;
use Illuminate\Http\Request;

class WarehouseStatisticsPdfOptions
{
    public const SECTIONS = [
        'show_weight' => 'Terhelési kapacitás',
        'show_pallets' => 'Raklapférőhely',
        'show_stock' => 'Helyi készlet összetétele',
        'show_counters' => 'Tárhely- és átmozgatási számlálók',
        'show_notes' => 'Magyarázatok és lefedettség',
    ];

    public static function defaults(): array
    {
        return ['orientation' => 'portrait'] + array_fill_keys(array_keys(self::SECTIONS), true);
    }

    public static function fromRequest(Request $request): array
    {
        $rules = ['preview' => 'nullable|boolean', 'orientation' => 'nullable|in:portrait,landscape', 'warehouse_id' => WarehouseStatistics::WAREHOUSE_FILTER];
        $data = $request->validate($rules + array_fill_keys(array_keys(self::SECTIONS), 'sometimes|boolean'));
        foreach (self::SECTIONS as $key => $label) $data[$key] = $request->boolean($key, true);

        return array_replace(self::defaults(), array_filter($data, fn ($value) => $value !== null));
    }
}
