<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ListingDates
{
    public static function rules(Request $request): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
        ];
    }

    public static function apply($query, array $filters, string $column): void
    {
        if ($filters['date_from'] ?? null) $query->where($column, '>=', Carbon::createFromFormat('!Y-m-d', $filters['date_from']));
        if ($filters['date_to'] ?? null) $query->where($column, '<', Carbon::createFromFormat('!Y-m-d', $filters['date_to'])->addDay());
    }

    public static function presets(string $route, array $filters): array
    {
        $today = Carbon::today();
        $ranges = [
            'today' => ['Ma', $today, $today],
            'last7' => ['Elmúlt 7 nap', $today->copy()->subDays(6), $today],
            'last30' => ['Elmúlt 30 nap', $today->copy()->subDays(29), $today],
            'month' => ['Ez a hónap', $today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        ];
        $presets = [];
        foreach ($ranges as $key => [$label, $from, $to]) {
            $from = $from->toDateString(); $to = $to->toDateString();
            $params = array_replace($filters, ['date_from' => $from, 'date_to' => $to]);
            unset($params['page']);
            $presets[$key] = ['label' => $label, 'from' => $from, 'to' => $to, 'url' => route($route, $params),
                'active' => ($filters['date_from'] ?? null) === $from && ($filters['date_to'] ?? null) === $to];
        }
        return $presets;
    }
}
