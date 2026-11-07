<?php

namespace App\Support;

use App\Models\{Product, User};

class AppSearch
{
    private const ACCENTS = ['Á' => 'a', 'á' => 'a', 'É' => 'e', 'é' => 'e', 'Í' => 'i', 'í' => 'i', 'Ó' => 'o', 'ó' => 'o',
        'Ö' => 'o', 'ö' => 'o', 'Ő' => 'o', 'ő' => 'o', 'Ú' => 'u', 'ú' => 'u', 'Ü' => 'u', 'ü' => 'u', 'Ű' => 'u', 'ű' => 'u'];

    private static function normalized(string $text): string { return mb_strtolower(strtr($text, self::ACCENTS)); }

    private static function field(string $column): string
    {
        // The same Hungarian normalization on MySQL and SQLite, including uppercase accents.
        foreach (self::ACCENTS as $from => $to) $column = "REPLACE({$column}, '{$from}', '{$to}')";
        return 'LOWER('.$column.')';
    }

    public static function results(User $user, string $query): array
    {
        $words = preg_split('/\s+/u', self::normalized(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        $pages = [];
        foreach (AppNavigation::groups($user) as $group) foreach ($group['items'] as $item) {
            $text = self::normalized($item['label'].' '.$group['label']);
            if (collect($words)->every(fn ($word) => str_contains($text, $word))) $pages[] = ['label' => $item['label'], 'detail' => $group['label'], 'kind' => 'Oldal', 'url' => route($item['route'])];
        }
        $canWork = in_array($user->role, ['admin', 'manager', 'worker'], true);
        $products = collect();
        if ($words && $canWork) {
            $builder = Product::query();
            foreach ($words as $word) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                $builder->where(fn ($q) => $q->whereRaw(self::field('product_name')." LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw(self::field('sku')." LIKE ? ESCAPE '!'", [$pattern]));
            }
            $products = $builder->orderBy('product_name')->orderBy('product_id')->limit(9)->get(['product_id', 'product_name', 'sku']);
        }
        return ['results' => array_merge($pages, $products->take(8)->map(fn ($product) => ['label' => $product->product_name,
            'detail' => $product->sku, 'kind' => 'Termék', 'url' => route('products.show', $product)])->all()),
            'moreUrl' => $products->count() > 8 ? route('products.index', ['search' => $query]) : null];
    }
}
