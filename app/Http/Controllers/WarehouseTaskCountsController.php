<?php

namespace App\Http\Controllers;

use App\Services\WarehouseOpenTaskCounts;
use Illuminate\Http\{JsonResponse, Request};

class WarehouseTaskCountsController extends Controller
{
    public function __invoke(Request $request, WarehouseOpenTaskCounts $counts): JsonResponse
    {
        return response()->json(['counts' => (object) $counts->forUser($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }
}
