<?php

namespace App\Support;

use App\Models\Warehouse;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class WarehouseStockContext
{
    public static function token(mixed $token): string { return is_string($token) && strlen($token) <= 4096 ? $token : ''; }
    public static function encode(array $filters): string { return Crypt::encryptString(json_encode($filters, JSON_THROW_ON_ERROR)); }
    public static function url(Warehouse $warehouse, mixed $token, string $context): string
    {
        $filters = [];
        try {
            $data = json_decode(Crypt::decryptString(self::token($token)), true);
            if (is_array($data)) foreach (['search', 'product_id', 'stock', 'sort_by', 'sort_dir', 'per_page', 'page'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) $filters[$key] = $data[$key];
            }
        } catch (DecryptException $e) {}
        return route('warehouses.show', ['warehouse' => $warehouse] + ($context !== '' ? ['context' => $context] : []) + $filters).'#local-stock';
    }
}
