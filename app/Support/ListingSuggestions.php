<?php

namespace App\Support;

use Illuminate\Http\{JsonResponse, Request};

/** Bounded suggestions from the same scoped query used by the list page. */
final class ListingSuggestions
{
    public static function requested(Request $request): bool
    {
        return $request->query('suggestions') === '1' && $request->expectsJson();
    }

    public static function hasQuery(array $filters): bool
    {
        return trim($filters['search'] ?? '') !== '';
    }

    public static function filters(array $filters): array
    {
        // The ninth row is only a has-more sentinel; never follow the list's current page.
        return array_replace($filters, ['page' => 1, 'per_page' => 9]);
    }

    public static function response(iterable $rows = [], ?callable $map = null): JsonResponse
    {
        $rows = collect($rows);

        return response()->json([
            'items' => $map ? $rows->take(8)->map($map)->values()->all() : [],
            'has_more' => $rows->count() > 8,
        ])->header('Cache-Control', 'private, no-store');
    }
}
