<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReturnQualityController extends Controller
{
    public function store(Request $request, InventoryMovement $movement, InventoryService $inventory)
    {
        try {
            return $this->process($request, $movement, $inventory);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) throw $exception;
            $context = $request->input('context');
            $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
            return redirect()->to(route('movements.show', ['movement' => $movement, 'context' => $context]).'#return-quality')
                ->withErrors($exception->errors())->withInput($request->only('request_key', 'action', 'quantity', 'reason', 'context'));
        }
    }

    private function process(Request $request, InventoryMovement $movement, InventoryService $inventory)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'action' => 'required|in:release,scrap',
            'quantity' => 'required|integer|min:1|max:2147483647', 'reason' => 'required|string|max:500']);
        $data['reason'] = Str::trim($data['reason']);
        if ($data['reason'] === '') throw ValidationException::withMessages(['reason' => 'A minősítés indoka kötelező.']);
        $context = $request->input('context');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        if ($request->input('edit') === '1') {
            return redirect()->to(route('movements.show', ['movement' => $movement, 'context' => $context]).'#return-quality')->withInput($data + ['context' => $context]);
        }
        $payload = ['return_movement_id' => (int)$movement->id, 'user_id' => (int)$request->user()->id,
            'action' => $data['action'], 'quantity' => (int)$data['quantity'], 'reason' => $data['reason']];
        if ($data['action'] === 'scrap' || $request->has('confirmation')) {
            if ($request->has('confirmation')) {
                try {
                    $token = $request->input('confirmation');
                    if (!is_string($token) || strlen($token) > 8192) throw new \RuntimeException();
                    $confirmed = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($confirmed) || ($confirmed['payload'] ?? null) !== $payload
                        || ($confirmed['key'] ?? null) !== strtolower($data['request_key'])
                        || !is_int($confirmed['expires'] ?? null) || $confirmed['expires'] <= now()->timestamp) throw new \RuntimeException();
                } catch (\Throwable $exception) {
                    throw ValidationException::withMessages(['confirmation' => 'A megerősítés lejárt vagy az adatok megváltoztak. Ellenőrizd és küldd be újra a minősítést.']);
                }
            } else {
                if (!$movement->isReturn() || $movement->type !== 'in' || $movement->quarantined_quantity <= 0) {
                    throw ValidationException::withMessages(['movement' => 'Csak elkülönítve bevételezett vevői visszáru minősíthető.']);
                }
                $previous = \App\Models\ReturnQualityAction::where('user_id', $request->user()->id)->where('request_key', strtolower($data['request_key']))->first();
                if ($previous && !hash_equals($previous->payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
                    throw ValidationException::withMessages(['request_key' => 'Ezt a beküldést már más adatokkal rögzítették. Új minősítéshez frissítsd az oldalt.']);
                }
                if ($previous) {
                    return redirect()->route('movements.show', ['movement' => $movement, 'context' => $context])
                        ->with('success', 'Ezt a selejtezést már rögzítettük: '.$previous->quantity.' db.');
                }
                $remaining = max(0, $movement->quarantined_quantity - (int)$movement->qualityActions()->sum('quantity'));
                if (!$previous && $payload['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['quantity' => 'A még minősíthető mennyiség legfeljebb '.$remaining.' db.']);
                }
                $movement->load(['product', 'warehouse']);
                $confirmation = Crypt::encryptString(json_encode(['payload' => $payload, 'key' => strtolower($data['request_key']), 'expires' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                return response()->view('movements.confirm-scrap', compact('movement', 'data', 'context', 'confirmation'))->header('Cache-Control', 'no-store');
            }
        }
        $action = $inventory->processReturnQuality($movement, $data + ['user_id' => $request->user()->id], $data['request_key']);
        return redirect()->route('movements.show', ['movement' => $movement, 'context' => $context])
            ->with('success', $action->action_label.' rögzítve: '.$action->quantity.' db.');
    }
}
