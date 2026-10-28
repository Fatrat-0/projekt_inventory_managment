<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class MovementListContext
{
    public static function encode(array $filters): string
    {
        return Crypt::encryptString(json_encode($filters, JSON_THROW_ON_ERROR));
    }

    public static function url(mixed $token): string
    {
        $filters = [];
        if (is_string($token) && strlen($token) <= 4096) {
            try {
                $decoded = json_decode(Crypt::decryptString($token), true);
                if (is_array($decoded)) {
                    foreach (['search', 'type', 'product_id', 'warehouse_id', 'user_id', 'date_from', 'date_to', 'sort_by', 'sort_dir', 'per_page', 'page'] as $key) {
                        if (isset($decoded[$key]) && is_scalar($decoded[$key])) $filters[$key] = $decoded[$key];
                    }
                }
            } catch (DecryptException $e) {
                // Invalid context returns to the unfiltered local list.
            }
        }
        return route('movements.index', $filters);
    }
}
