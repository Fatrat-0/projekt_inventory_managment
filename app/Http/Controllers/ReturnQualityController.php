<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class ReturnQualityController extends Controller
{
    public function store(Request $request, InventoryMovement $movement, InventoryService $inventory)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'action' => 'required|in:release,scrap',
            'quantity' => 'required|integer|min:1|max:2147483647', 'reason' => 'required|string|max:500']);
        $action = $inventory->processReturnQuality($movement, $data + ['user_id' => $request->user()->id], $data['request_key']);
        $context = $request->input('context');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        return redirect()->route('movements.show', ['movement' => $movement, 'context' => $context])
            ->with('success', $action->action_label.' rögzítve: '.$action->quantity.' db.');
    }
}
