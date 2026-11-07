<?php

namespace App\Http\Controllers;

use App\Support\AppSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AppSearchController extends Controller
{
    public function index(Request $request)
    {
        try { $data = $request->validate(['q' => 'nullable|string|max:200']); }
        catch (ValidationException $exception) {
            if ($request->expectsJson()) throw $exception;
            return redirect()->route('app-search.index')->withErrors(['q' => 'A keresés legfeljebb 200 karakteres szöveg lehet.']);
        }
        $query = trim($data['q'] ?? '');
        $results = AppSearch::results($request->user(), $query);
        $response = $request->expectsJson() ? response()->json($results) : response()->view('search.index', $results + ['query' => $query]);
        return $response->header('Cache-Control', 'no-store, private');
    }
}
