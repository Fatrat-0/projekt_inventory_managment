<?php

namespace App\Support;

use App\Queries\StockListing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class StockListContext
{
    public static function encode(array $filters): string { return Crypt::encryptString(json_encode($filters, JSON_THROW_ON_ERROR)); }

    public static function url(mixed $token, ?int $warehouseId = null): string
    {
        try {
            if (is_string($token) && strlen($token) <= 4096) {
                $data = json_decode(Crypt::decryptString($token), true);
                if (is_array($data)) {
                    $request = Request::create('/stock', 'GET', array_intersect_key($data, array_flip(['search', 'product_id', 'warehouse_id', 'category_id', 'stock_status', 'sort_by', 'sort_dir', 'per_page', 'page'])));
                    $listing = new StockListing($request);
                    return route('stock.index', $listing->filters + ($request->filled('page') ? ['page' => $request->integer('page')] : []));
                }
            }
        } catch (DecryptException|ValidationException $exception) {}
        return route('stock.index', $warehouseId !== null ? ['warehouse_id' => $warehouseId] : []);
    }
}
