<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\WarehouseLoadVerificationService;
use Illuminate\Http\{JsonResponse, Request};

class WarehouseLoadVerificationController extends Controller
{
    public function __construct(private WarehouseLoadVerificationService $verifications) {}

    public function index(Request $request, Warehouse $warehouse): JsonResponse
    {
        return $this->response($this->verifications->pending($request->user(), $warehouse, $this->filters($request)));
    }

    public function show(Request $request, Warehouse $warehouse, int $placement): JsonResponse
    {
        return $this->response($this->verifications->show($request->user(), $warehouse, $placement));
    }

    public function preview(Request $request, Warehouse $warehouse, int $placement): JsonResponse
    {
        return $this->response($this->verifications->preview($request->user(), $warehouse, $placement, $request->all()));
    }

    public function store(Request $request, Warehouse $warehouse, int $placement): JsonResponse
    {
        $audit = $this->verifications->confirm($request->user(), $warehouse, $placement, $request->all());
        $result = ['verification' => $audit->toArray(), 'discrepancy_open' => $audit->capacity_violations !== []];
        if ($audit->result === 'rejected') {
            return $this->response([
                'message' => $result['discrepancy_open']
                    ? 'A helyszíni mérés kapacitáseltérést mutat. A mérés naplózva, az érintett tárhely terhelése korlátozott.'
                    : 'A mért terhelés nem felel meg a rekesz szabályainak. A tétel ellenőrzésre vár.',
                'errors' => $audit->errors,
            ] + $result, 422);
        }

        return $this->response($result, 201);
    }

    public function history(Request $request, Warehouse $warehouse): JsonResponse
    {
        return $this->response($this->verifications->history($request->user(), $warehouse, $this->filters($request, history: true)));
    }

    private function filters(Request $request, bool $history = false): array
    {
        $rules = [
            'location_id' => 'sometimes|integer|min:1', 'product_id' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1|max:2147483647',
        ];
        if ($history) $rules['placement_id'] = 'sometimes|integer|min:1';

        return $request->validate($rules, [
            'location_id.*' => 'Érvényes rekeszazonosító szükséges.',
            'product_id.*' => 'Érvényes termékazonosító szükséges.',
            'placement_id.*' => 'Érvényes rekesztétel-azonosító szükséges.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti tétel kérhető le.',
            'page.*' => 'Érvényes oldalszám szükséges.',
        ]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
