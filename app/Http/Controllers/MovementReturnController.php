<?php

namespace App\Http\Controllers;

use App\Models\{InventoryMovement, Stock};
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MovementReturnController extends Controller
{
    public function create(Request $request, InventoryMovement $movement)
    {
        abort_unless($movement->isReturnable(), 404);
        $movement->load(['product', 'warehouse', 'partner'])->loadSum('returns', 'quantity');
        $remaining = max(0, $movement->quantity - (int)$movement->returns_sum_quantity);
        $stock = (int)Stock::where('product_id', $movement->product_id)->where('warehouse_id', $movement->warehouse_id)->first()?->available_quantity;
        $context = $request->old('context', $request->query('context', ''));
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        $returnUrl = route('movements.show', ['movement' => $movement, 'context' => $context]);
        $requestKey = $request->old('request_key');
        if (!is_string($requestKey) || !Str::isUuid($requestKey)) $requestKey = (string)Str::uuid();
        return view('movements.return', compact('movement', 'remaining', 'stock', 'context', 'returnUrl', 'requestKey'));
    }

    public function store(Request $request, InventoryMovement $movement, InventoryService $inventory)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:2147483647',
            'return_reason' => 'required|string|max:500', 'reference' => 'nullable|string|max:255'], [], ['return_reason' => 'visszáru indoka']);
        $recorded = $inventory->recordReturn($movement, $data + ['user_id' => $request->user()->id], $data['request_key']);
        // Keep the bounded token; the detail page resolves it to a local list URL.
        $context = $request->input('context');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        return redirect()->route('movements.show', ['movement' => $recorded, 'context' => $context])
            ->with('success', 'Visszáru rögzítve: '.$recorded->movement_number.'.');
    }
}
