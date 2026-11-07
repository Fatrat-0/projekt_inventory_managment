<?php

namespace App\Support;

class ListingColumns
{
    public static function forRoute(?string $route): array
    {
        $columns = match (explode('.', $route ?? '')[0]) {
            'products' => ['sku' => 'Cikkszám (SKU)', 'product_name' => ['Terméknév', true], 'category' => 'Kategória', 'stock' => 'Készlet és minimum', 'price' => 'Egységár', 'value' => 'Készletérték', 'actions' => ['Műveletek', true]],
            'stock' => ['product_name' => ['Termék', true], 'sku' => 'Cikkszám (SKU)', 'warehouse_name' => 'Raktár', 'quantity' => 'Fizikai készlet', 'available' => 'Kiadható készlet', 'quarantined_quantity' => 'Elkülönített készlet', 'min_quantity' => 'Helyi minimum', 'actions' => ['Műveletek', true]],
            'movements' => ['created_at' => 'Dátum', 'type' => 'Mozgás típusa', 'product_name' => ['Termék', true], 'warehouse_name' => 'Raktár', 'quantity' => 'Mennyiség', 'user_name' => 'Rögzítette', 'reference' => 'Hivatkozás'],
            'transfers' => ['created_at' => 'Dátum', 'product_name' => ['Termék', true], 'quantity' => 'Mennyiség', 'status' => 'Állapot', 'route' => 'Útvonal', 'actions' => ['Műveletek', true]],
            'shipments' => ['created_at' => 'Létrehozás ideje', 'reference' => ['Hivatkozás', true], 'status' => 'Állapot', 'route' => 'Útvonal', 'vehicle' => 'Jármű', 'progress' => 'Átvett megállók'],
            'partners' => ['name' => ['Név / Cégnév', true], 'type' => 'Típus', 'email' => 'E-mail', 'phone' => 'Telefon', 'actions' => ['Műveletek', true]],
            'categories' => ['category_name' => ['Kategória', true], 'default_item_min_stock' => 'Termékminimum', 'aggregate_min_stock' => 'Kategória minimum', 'products_count' => 'Közvetlen termékek', 'children_count' => 'Alkategóriák', 'actions' => ['Műveletek', true]],
            'warehouses' => ['warehouse_name' => ['Raktár neve', true], 'location' => 'Cím', 'products_count' => 'Készleten lévő termékfajták', 'total_quantity' => 'Összkészlet', 'actions' => ['Műveletek', true]],
            'quality-returns' => ['created_at' => 'Beérkezés', 'product_name' => ['Termék', true], 'warehouse_name' => 'Raktár', 'original_quantity' => 'Eredeti', 'released_quantity' => 'Felszabadítva', 'scrapped_quantity' => 'Selejtezve', 'remaining_quantity' => 'Hátralévő', 'status' => 'Állapot', 'actions' => ['Művelet', true]],
            'users' => ['name' => ['Név', true], 'email' => 'E-mail', 'created_at' => 'Regisztráció ideje', 'actions' => ['Műveletek', true]],
            default => [],
        };

        return collect($columns)->map(fn ($definition, $key) => [
            'key' => $key,
            'label' => is_array($definition) ? $definition[0] : $definition,
            'required' => is_array($definition) && ($definition[1] ?? false),
        ])->values()->all();
    }
}
