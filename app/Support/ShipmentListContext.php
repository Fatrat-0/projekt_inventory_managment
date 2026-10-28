<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class ShipmentListContext
{
    public static function token(mixed $token): string { return is_string($token) && strlen($token) <= 4096 ? $token : ''; }
    public static function encode(array $filters): string { return Crypt::encryptString(json_encode($filters, JSON_THROW_ON_ERROR)); }
    public static function url(mixed $token): string
    {
        $filters = [];
        try {
            $data = json_decode(Crypt::decryptString(self::token($token)), true);
            if (is_array($data)) foreach (['search','status','origin_warehouse_id','destination_warehouse_id','product_id','date_from','date_to','sort_by','sort_dir','per_page','page'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) $filters[$key] = $data[$key];
            }
        } catch (DecryptException $e) {}
        return route('shipments.index', $filters);
    }
}
