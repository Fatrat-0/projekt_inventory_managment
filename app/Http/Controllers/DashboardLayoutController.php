<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DashboardLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardLayoutController extends Controller
{
    public function edit(Request $request)
    {
        $stored = DashboardLayout::normalize($request->user()->dashboard_layout);
        $ownInput = old('_layout_form') === '1';
        $layout = $ownInput ? DashboardLayout::draft(old('order', $stored['order']), old('visible', []), $stored) : $stored;
        $version = filter_var($ownInput ? old('version') : $request->user()->dashboard_layout_version, FILTER_VALIDATE_INT);
        $invalidVersion = $ownInput && ($version === false || $version < 0 || $version > 2147483647 || $request->session()->get('errors')?->has('version'));
        // Never attach a fresh revision to an old draft without an explicit reload/review.
        return $this->page($request, $layout, $version === false ? (int)$request->user()->dashboard_layout_version : $version, $invalidVersion ? 'invalid_version' : null);
    }

    public function update(Request $request)
    {
        $ids = array_keys(DashboardLayout::PANELS);
        $moves = array_merge(array_map(fn ($id) => 'up:'.$id, $ids), array_map(fn ($id) => 'down:'.$id, $ids));
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'order' => ['required', 'array', 'list', 'size:'.count($ids)],
            'order.*' => ['required', 'string', 'distinct:strict', Rule::in($ids)],
            'visible' => ['sometimes', 'array', 'list', 'max:'.count($ids)],
            'visible.*' => ['required', 'string', 'distinct:strict', Rule::in($ids)],
            'move' => ['sometimes', 'string', Rule::in($moves), 'prohibits:reset'],
            'reset' => ['sometimes', 'accepted', 'prohibits:move'],
        ], [
            'order.*' => 'A blokkok sorrendje nem érvényes. Minden blokk egyszer szerepeljen.',
            'visible.*' => 'A megjelenítendő blokkok listája nem érvényes.',
            'version.*' => 'A beállítás verziója nem érvényes. Töltsd be újra a beállítóoldalt.',
        ]);
        $layout = ['order' => $data['order'], 'visible' => $data['visible'] ?? []];
        if (isset($data['reset'])) $layout = DashboardLayout::defaults();
        if (isset($data['move'])) {
            [$direction, $id] = explode(':', $data['move']);
            $index = array_search($id, $layout['order'], true);
            $next = $index + ($direction === 'up' ? -1 : 1);
            if ($next >= 0 && $next < count($ids)) [$layout['order'][$index], $layout['order'][$next]] = [$layout['order'][$next], $layout['order'][$index]];
        }
        // Only this user's preference is changed, and a stale tab cannot overwrite a newer layout.
        $changed = User::whereKey($request->user()->id)->where('dashboard_layout_version', $data['version'])->update([
            'dashboard_layout' => json_encode($layout, JSON_THROW_ON_ERROR),
            'dashboard_layout_version' => DB::raw('dashboard_layout_version + 1'),
        ]);
        if (!$changed) {
            if ($request->expectsJson()) return response()->json(['message' => 'Az elrendezés közben megváltozott. Töltsd be a mentett beállításokat.'], 409);
            return $this->page($request, $layout, (int)$data['version'], 'stale', 409);
        }
        $params = DashboardLayout::context($request, $layout['visible']);
        if ($request->expectsJson()) return response()->json(['layout' => $layout, 'version' => (int)$data['version'] + 1, 'redirect' => route('dashboard', $params)]);
        if (isset($data['move']) || isset($data['reset'])) {
            $fragment = isset($id) ? '#layout-'.$id : '#layout-form-title';
            return redirect()->to(route('dashboard.customize', $params).$fragment)->with('success', 'A vezérlőpult elrendezése mentve.');
        }
        return redirect()->route('dashboard', $params)->with('success', 'A vezérlőpult elrendezése mentve.');
    }

    private function page(Request $request, array $layout, int $version, ?string $reloadReason = null, int $status = 200)
    {
        return response()->view('dashboard.customize', [
            'layout' => $layout, 'layoutVersion' => $version, 'panels' => DashboardLayout::PANELS,
            'defaults' => DashboardLayout::defaults(), 'context' => DashboardLayout::context($request), 'conflict' => $reloadReason !== null, 'reloadReason' => $reloadReason,
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
