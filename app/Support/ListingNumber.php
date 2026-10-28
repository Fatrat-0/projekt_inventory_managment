<?php

namespace App\Support;

class ListingNumber
{
    public static function id(string $token, string $prefix): ?int
    {
        if (!preg_match('/^'.preg_quote($prefix, '/').'-([0-9]+)$/i', $token, $matches)) return null;
        $digits = ltrim($matches[1], '0');
        $max = (string) PHP_INT_MAX;
        if ($digits === '' || strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) return null;
        return (int) $digits;
    }
}
