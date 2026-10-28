<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class TransferListContext
{
    public static function encode(array $filters): string { return Crypt::encryptString(json_encode($filters, JSON_THROW_ON_ERROR)); }
    public static function url(mixed $token): string
    {
        $filters = [];
        if (is_string($token) && strlen($token) <= 4096) {
            try {
                $data = json_decode(Crypt::decryptString($token), true);
                if (is_array($data)) foreach (['search', 'status', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'sort_by', 'sort_dir', 'per_page', 'page'] as $key) {
                    if (isset($data[$key]) && is_scalar($data[$key])) $filters[$key] = $data[$key];
                }
            } catch (DecryptException $e) {}
        }
        return route('transfers.index', $filters);
    }
}
