<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\Partner;
use App\Models\Shipment;
use App\Policies\TransferIntentPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use App\Services\InventoryService;
use App\Services\SmartPicking;
use App\Services\WarehouseStockAvailability;

class InventoryMovementController extends Controller
{
    public function index(Request $request)
    {
        $listing = new \App\Queries\MovementListing($request);
        $filters = $listing->filters;
        $summary = $listing->query()->reorder()->selectRaw("type, SUM(quantity) as total")->groupBy('type')->pluck('total', 'type');
        $context = \App\Support\MovementListContext::encode($filters + ['page' => $request->integer('page', 1)]);
        return view('movements.index', [
            'movements' => $listing->query()->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters, 'filterLabels' => $listing->labels(),
            'datePresets' => $listing->datePresets(),
            'listContext' => $context,
            'createUrl' => route('movements.create', ['context' => $context]),
            'sortOptions' => \App\Queries\MovementListing::SORTS,
            'products' => Product::orderBy('product_name')->get(),
            'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
            'users' => \App\Models\User::orderBy('name')->get(),
            'partners' => Partner::orderBy('name')->orderBy('id')->get(),
            'totalIn' => (int) ($summary['in'] ?? 0), 'totalOut' => (int) ($summary['out'] ?? 0),
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $request->validate(['product_id' => 'nullable|integer|exists:products,product_id', 'warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id']);
        $products = Product::with('defaultPackaging:id,product_id,pieces_per_package')->orderBy('product_name')->get();
        $warehouses = Warehouse::orderBy('warehouse_name')->get();
        $partners = Partner::whereNull('archived_at')->orderBy('name')->orderBy('id')->get()
            ->map(fn (Partner $partner) => [
                'value' => (string) $partner->id,
                'name' => $partner->name,
                'label' => collect([$partner->name, $partner->contact_name, $partner->email, '#'.$partner->id])
                    ->filter(fn ($value) => filled($value))->implode(' · '),
                'types' => array_values(array_filter(['in', 'out'], $partner->supportsMovement(...))),
            ]);
        if ($request->expectsJson()) {
            return response()->json($partners)->header('Cache-Control', 'private, no-store');
        }
        $context = $request->old('context', $request->query('context', ''));
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        $returnUrl = \App\Support\MovementListContext::url($context);
        $requestKey = $request->old('request_key');
        if (!is_string($requestKey) || !\Illuminate\Support\Str::isUuid($requestKey)) $requestKey = (string) \Illuminate\Support\Str::uuid();
        return view('movements.create', compact('products', 'warehouses', 'partners', 'context', 'returnUrl', 'requestKey', 'prefill'));
    }

    public function show(Request $request, InventoryMovement $movement)
    {
        $movement->load(['product', 'warehouse', 'user', 'partner', 'returnOf', 'qualityAction.user'])->loadSum('returns', 'quantity');
        $receiptBatch = $movement->type === 'in' ? $movement->manualReceiptBatch : null;
        $canPutaway = $receiptBatch && Gate::allows('putawayInWarehouse', [Shipment::class, (int) $receiptBatch->warehouse_id]);
        $recentPutaways = $canPutaway && $receiptBatch->pending_putaway_quantity === 0
            ? $receiptBatch->putaways()->latest('id')->limit(3)->get(['location_code', 'quantity', 'placed_at'])
            : collect();
        $returned = (int)$movement->returns_sum_quantity;
        $remaining = max(0, $movement->quantity - $returned);
        $returnHistory = $movement->returns()->with('user')->orderByDesc('id')->paginate(10, ['*'], 'returns_page')->withQueryString();
        $context = $request->query('context');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        if ($returnHistory->currentPage() > $returnHistory->lastPage()) {
            $params = ['movement' => $movement];
            if ($context !== '') $params['context'] = $context;
            if ($returnHistory->lastPage() > 1) $params['returns_page'] = $returnHistory->lastPage();
            return redirect()->route('movements.show', $params);
        }
        $destination = \App\Support\MovementListContext::destination($request->query('context'));
        $returnUrl = $destination['url'];
        $returnLabel = $destination['label'];
        $qualityHistory = $movement->qualityActions()->with(['user', 'movement'])->orderByDesc('id')->paginate(10, ['*'], 'quality_page')->withQueryString();
        if ($qualityHistory->currentPage() > $qualityHistory->lastPage()) {
            $params = ['movement' => $movement];
            if ($context !== '') $params['context'] = $context;
            if ($qualityHistory->lastPage() > 1) $params['quality_page'] = $qualityHistory->lastPage();
            return redirect()->route('movements.show', $params);
        }
        $qualityTotals = $movement->qualityActions()->toBase()->selectRaw("COALESCE(SUM(CASE WHEN action = 'release' THEN quantity ELSE 0 END), 0) AS released, COALESCE(SUM(CASE WHEN action = 'scrap' THEN quantity ELSE 0 END), 0) AS scrapped, COALESCE(SUM(quantity), 0) AS processed")->first();
        $qualityReleased = (int)$qualityTotals->released;
        $qualityScrapped = (int)$qualityTotals->scrapped;
        $qualityRemaining = max(0, $movement->quarantined_quantity - (int)$qualityTotals->processed);
        $qualityKey = $request->old('request_key');
        if (!is_string($qualityKey) || !\Illuminate\Support\Str::isUuid($qualityKey)) $qualityKey = (string)\Illuminate\Support\Str::uuid();
        $originLink = $this->originLink($request, $movement);
        $sourceLocations = collect();
        $hasIssueSources = $movement->type === 'out' && $movement->isReturnable();
        if ($hasIssueSources) {
            $movement->load('issueSources');
            $sourceLocations = WarehouseLocation::where('warehouse_id', $movement->warehouse_id)
                ->whereKey($movement->issueSources->pluck('warehouse_location_id')->filter()->all())->get()->keyBy('id');
        }

        return view('movements.show', compact('movement', 'returnUrl', 'returnLabel', 'returned', 'remaining', 'returnHistory', 'context', 'qualityHistory', 'qualityRemaining', 'qualityReleased', 'qualityScrapped', 'qualityKey', 'originLink', 'hasIssueSources', 'sourceLocations', 'receiptBatch', 'canPutaway', 'recentPutaways'));
    }

    private function originLink(Request $request, InventoryMovement $movement): ?array
    {
        $actor = $request->user();
        if ($movement->isShipmentReceipt()) {
            $item = $movement->shipmentReceiptItem;
            $receipt = $item?->receipt;
            $stop = $item?->transitItem?->stop;
            if ($receipt && $stop && $actor->can('receiveStop', [\App\Models\Shipment::class, $stop])) {
                return ['url' => route('warehouse-receiving-pages.show', [$receipt->shipment_id, $stop]),
                    'label' => 'Átvételi munkalap megnyitása'];
            }
        }
        $shipment = $movement->isShipmentDispatch() ? $movement->shipmentTransitItem?->shipment
            : ($movement->isShipmentReceipt() ? $movement->shipmentReceiptItem?->receipt?->shipment : null);
        if ($shipment && $actor->can('viewPlan', $shipment)) {
            return ['url' => route('shipments.show', $shipment), 'label' => 'Fuvar megnyitása'];
        }

        return null;
    }

    public function stock(Request $request, WarehouseStockAvailability $availability)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'details' => 'sometimes|boolean',
        ]);
        $stock = $availability->snapshot($data['warehouse_id'], $data['product_id']);
        $balance = ['quantity' => $stock['physical_quantity'] - $stock['quarantined_quantity'] - $stock['pending_putaway_quantity'],
            'physical_quantity' => $stock['physical_quantity'], 'quarantined_quantity' => $stock['quarantined_quantity']];
        if ($stock['assigned_quantity']) $balance['assigned_quantity'] = $stock['assigned_quantity'];
        if ($stock['pending_putaway_quantity']) $balance['pending_putaway_quantity'] = $stock['pending_putaway_quantity'];
        if ($stock['source_reserved_quantity'] || $stock['staged_quantity'] || $stock['pending_putaway_quantity'] || !$stock['consistent']) {
            $balance['source_reserved_quantity'] = $stock['source_reserved_quantity'];
            $balance['direct_issue_quantity'] = $stock['direct_issue_quantity'];
            $balance['staged_quantity'] = $stock['staged_quantity'];
        }
        if ($request->boolean('details')) {
            $balance += [
                'direct_issue_quantity' => $stock['direct_issue_quantity'],
                'source_reserved_quantity' => $stock['source_reserved_quantity'],
                'staged_quantity' => $stock['staged_quantity'],
                'pending_putaway_quantity' => $stock['pending_putaway_quantity'],
                'assigned_quantity' => $stock['assigned_quantity'],
                'pickable_quantity' => $stock['pickable_quantity'],
                'inactive_bin_quantity' => collect($stock['locations'])->where('active', false)->sum('stored_quantity'),
                'consistent' => $stock['consistent'],
            ];
        }

        return response()->json($balance)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $validated = $request->validate([
            'request_key' => 'required|uuid',
            'product_id' => 'required|exists:products,product_id',
            'warehouse_id' => 'required|exists:warehouses,warehouse_id',
            'type' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1|max:2147483647',
            'reference' => 'nullable|string|max:255',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'source_allocations' => 'sometimes|required|array|list',
        ], [
            'source_allocations.*' => 'Adj meg legalább egy érvényes forrást.',
        ]);

        // Hozzáadjuk a bejelentkezett felhasználó ID-ját, aki a mozgást rögzítette
        $validated['user_id'] = auth()->id();

        // A készletváltozás és a naplóbejegyzés egyetlen tranzakció.
        $requestKey = $validated['request_key'];
        unset($validated['request_key']);
        if ($validated['type'] === 'in') {
            if ($request->expectsJson()) {
                $request->validate(['confirmed' => 'required|accepted'], [
                    'confirmed.*' => 'Erősítsd meg az áru tényleges átvételét.',
                ]);
            }
            $movement = $inventory->receiveManual($validated, $requestKey);
            if ($request->expectsJson()) {
                $batch = $movement->manualReceiptBatch;
                $state = $batch?->state();
                $canPutaway = $state && $state['can_putaway'] && Gate::allows('putawayInWarehouse', [Shipment::class, (int) $batch->warehouse_id]);
                $context = $request->input('context');

                return response()->json([
                    'movement' => $movement->only(['id', 'movement_number', 'product_id', 'warehouse_id', 'quantity',
                        'partner_id', 'partner_name', 'reference', 'stock_before', 'stock_after']),
                    'batch' => $state,
                    'movement_url' => route('movements.show', ['movement' => $movement, 'context' => is_string($context) && strlen($context) <= 4096 ? $context : null]),
                    'putaway_url' => $canPutaway ? route('warehouse-putaway-pages.manual', $batch) : null,
                ], 201)->header('Cache-Control', 'private, no-store');
            }
        } else {
            $movement = $inventory->recordMovement($validated, $requestKey);
        }

        if ($request->expectsJson() && isset($validated['source_allocations'])) return $this->issueHistory($movement);

        return redirect()->to(\App\Support\MovementListContext::url($request->input('context')))->with('success', 'Mozgás rögzítve: '.$movement->movement_number.'.');
    }

    public function issueOptions(Request $request, TransferIntentPolicy $policy)
    {
        $filters = $request->validate([
            'product_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
        ], [
            'product_id.*' => 'Válassz érvényes terméket.',
            'warehouse_id.*' => 'Válassz érvényes raktárat.',
        ]);
        $warehouses = $policy->pickingWarehouses(Warehouse::query(), $request->user())->select('warehouse_id');
        $stock = DB::query()->fromSub(WarehouseStockAvailability::listingRows(), 'availability')
            ->whereIn('warehouse_id', $warehouses)->where('pickable_quantity', '>', 0);

        return response()->json([
            'products' => $this->issueChoices(clone $stock, 'product_id', 'warehouse_id', $filters['warehouse_id'] ?? null),
            'warehouses' => $this->issueChoices($stock, 'warehouse_id', 'product_id', $filters['product_id'] ?? null),
        ])->header('Cache-Control', 'no-store');
    }

    private function issueChoices(Builder $stock, string $id, string $oppositeId, ?int $filter): array
    {
        if ($filter === null) {
            return $stock->distinct()->orderBy($id)->pluck($id)->map(fn ($value) => ['id' => (string) $value])->all();
        }

        return $stock->where($oppositeId, $filter)->orderBy($id)->get([$id, 'pickable_quantity', 'direct_issue_quantity'])
            ->map(fn ($row) => ['id' => (string) $row->{$id}, 'pickable_quantity' => (int) $row->pickable_quantity,
                'direct_issue_quantity' => (int) $row->direct_issue_quantity])->all();
    }

    public function issueSources(Request $request, SmartPicking $picking)
    {
        $input = $request->validate([
            'recommend_bins' => 'sometimes|boolean',
            'source_allocations' => 'sometimes|array|list',
        ], [
            'recommend_bins.boolean' => 'Érvénytelen ajánlási mód.',
            'source_allocations.*' => 'A kijelölt források formátuma érvénytelen.',
        ]);
        $recommendBins = $request->boolean('recommend_bins');
        $data = $this->issueInput($request, $recommendBins);
        $options = $picking->issueOptions($data['warehouse_id'], $data['product_id'], $data['quantity'] ?? null,
            $recommendBins ? ($input['source_allocations'] ?? []) : null);
        $ids = array_values(array_filter(array_column($options['sources'], 'location_id')));
        $locations = WarehouseLocation::where('warehouse_id', $data['warehouse_id'])->whereKey($ids)
            ->with(['system.zone', 'level'])
            ->withExists(['placements as load_requires_verification' => fn ($query) => $query->where('load_requires_verification', true)])
            ->get()->keyBy('id');
        foreach ($options['sources'] as &$source) {
            $location = $locations->get($source['location_id']);
            if (!$location) continue;
            $planObject = $location->system ?? $location;
            $source['position_label'] = implode(' · ', array_filter($location->pickingPosition()));
            $source['load_requires_verification'] = (bool) $location->load_requires_verification;
            $source['plan_url'] = $planObject->floor_x === null || $planObject->floor_y === null ? null
                : route('warehouses.floor-plan.show', ['warehouse' => $location->warehouse_id] + ($location->system_id
                    ? ['system' => $location->system_id, 'bin' => $location->id] : ['location' => $location->id]));
        }
        unset($source);

        return response()->json($options)->header('Cache-Control', 'no-store');
    }

    public function validateIssue(Request $request, SmartPicking $picking)
    {
        $data = $this->issueInput($request, true);
        $picking->validateIssue($data['warehouse_id'], $data['product_id'], $data['quantity'], $request->input('source_allocations'));

        return response()->json(['valid' => true])->header('Cache-Control', 'no-store');
    }

    public function issueHistory(InventoryMovement $movement)
    {
        abort_unless($movement->type === 'out' && $movement->isReturnable(), 404);
        $movement->load(['issueSources', 'user:id,name']);

        return response()->json([
            'id' => $movement->id, 'movement_number' => $movement->movement_number,
            'product_id' => (int) $movement->product_id, 'warehouse_id' => (int) $movement->warehouse_id,
            'quantity' => $movement->quantity, 'reference' => $movement->reference,
            'stock_before' => $movement->stock_before, 'stock_after' => $movement->stock_after,
            'user_id' => (int) $movement->user_id, 'user_name' => $movement->user?->name,
            'created_at' => $movement->created_at?->toISOString(),
            'sources' => $movement->issueSources->map(fn ($source) => [
                'source_type' => $source->source_type, 'location_id' => $source->warehouse_location_id,
                'code' => $source->location_code, 'name' => $source->location_name, 'quantity' => $source->quantity,
            ]),
        ])->header('Cache-Control', 'no-store');
    }

    private function issueInput(Request $request, bool $requiresQuantity): array
    {
        return $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'quantity' => ($requiresQuantity ? 'required' : 'nullable').'|integer|min:1|max:2147483647',
        ], [
            'product_id.*' => 'Válassz létező terméket.',
            'warehouse_id.*' => 'Válassz létező raktárat.',
            'quantity.*' => 'Adj meg érvényes, pozitív egész darabszámot.',
        ]);
    }
}
