<?php

namespace App\Http\Controllers;

use App\Queries\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        try {
            $data = $request->validate([
                'days' => ['nullable', 'integer', Rule::in([7, 30])],
                'chart' => ['nullable', Rule::in(['bar', 'line'])],
            ] + array_fill_keys(array_keys(DashboardOverview::PANEL_PAGES), 'nullable|integer|min:1|max:2147483647'));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) throw $exception;
            $messages = [];
            if (isset($exception->errors()['days'])) $messages['days'] = 'Az időszak nem érvényes. Az utolsó 7 napra állítottuk vissza.';
            if (isset($exception->errors()['chart'])) $messages['chart'] = 'A diagram típusa nem érvényes. Oszlopdiagramra állítottuk vissza.';
            foreach (array_keys(DashboardOverview::PANEL_PAGES) as $key) {
                if (isset($exception->errors()[$key])) $messages[$key] = 'A lista oldalszáma nem érvényes. Az első oldalra állítottuk vissza.';
            }
            $params = [];
            if (in_array($request->input('days'), [30, '30'], true)) $params['days'] = 30;
            if ($request->input('chart') === 'line') $params['chart'] = 'line';
            foreach (array_keys(DashboardOverview::PANEL_PAGES) as $key) {
                $page = filter_var($request->input($key), FILTER_VALIDATE_INT);
                if ($request->has($key) && $page !== false && $page >= 1 && $page <= 2147483647) $params[$key] = $page;
            }
            return redirect()->route('dashboard', $params)->withErrors($messages);
        }
        $days = (int)($data['days'] ?? 7);
        $overview = (new DashboardOverview)->data($days, array_intersect_key($data, DashboardOverview::PANEL_PAGES));
        $params = ['days' => $days, 'chart' => $data['chart'] ?? 'bar'];
        $overflow = null;
        foreach (DashboardOverview::PANEL_PAGES as $key => $panel) {
            if ($request->has($key)) $params[$key] = $overview[$panel['data']]->currentPage();
            if (($data[$key] ?? 1) > $overview[$panel['data']]->currentPage()) $overflow = $panel['anchor'];
        }
        if ($overflow) return redirect()->to(route('dashboard', $params).'#'.$overflow);
        foreach (DashboardOverview::PANEL_PAGES as $panel) $overview[$panel['data']]->appends($params)->fragment($panel['anchor']);
        $listPageParams = array_intersect_key($params, DashboardOverview::PANEL_PAGES);
        return response()->view('dashboard', $overview + ['chartType' => $data['chart'] ?? 'bar', 'listPageParams' => $listPageParams])
            ->header('Cache-Control', 'no-store, private');
    }
}
