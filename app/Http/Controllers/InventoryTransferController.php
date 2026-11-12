<?php

namespace App\Http\Controllers;

use App\Models\{InventoryTransfer, Product, Stock, Warehouse, WarehouseLocation};
use App\Queries\TransferListing;
use App\Queries\ReplenishmentSuggestions;
use App\Services\InventoryService;
use App\Services\SmartPicking;
use App\Services\SmartPutaway;
use App\Services\TransferCargoWeight;
use App\Services\WarehouseCapacity;
use App\Support\TransferListContext;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryTransferController extends Controller
{
    public function index(Request $request)
    {
        $listing = new TransferListing($request);
        $filters = $listing->filters;
        $context = TransferListContext::encode($filters + ['page' => $request->integer('page', 1)]);
        return view('transfers.index', [
            'transfers' => $listing->query()->paginate($filters['per_page'])->withQueryString(),
            'pendingQuantity' => (int)$listing->query()->reorder()->where('status', 'pending')->sum('quantity'),
            'filters' => $filters, 'labels' => $listing->labels(), 'context' => $context,
            'datePresets' => \App\Support\ListingDates::presets('transfers.index', $filters),
            'statusCounts' => $listing->query(true)->reorder()->toBase()->select('inventory_transfers.status')->selectRaw('COUNT(*) as total')->groupBy('inventory_transfers.status')->pluck('total', 'status')->all(),
            'products' => Product::orderBy('product_name')->get(), 'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
        ]);
    }
    public function create(Request $request)
    {
        $context = $request->old('context', $request->query('context', ''));
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        $requestKey = $request->old('request_key');
        if (!is_string($requestKey) || !Str::isUuid($requestKey)) $requestKey = (string)Str::uuid();
        $prefill = [];
        if ($request->hasAny(['replenishment_stock_id', 'source_stock_id'])) {
            $selection = $request->validate(['replenishment_stock_id' => 'required|integer|min:1', 'source_stock_id' => 'required|integer|min:1']);
            $proposal = ReplenishmentSuggestions::query()->where('needs.id', $selection['replenishment_stock_id'])->where('needs.source_stock_id', $selection['source_stock_id'])->first();
            if (!$proposal) return redirect()->to(route('dashboard').'#replenishment-title')->withErrors(['replenishment' => 'Ez az utánpótlási javaslat már nem aktuális. Válassz a frissített listából.']);
            $prefill = ['replenishment_stock_id' => (int)$proposal->id, 'product_id' => (int)$proposal->product_id,
                'from_warehouse_id' => (int)$proposal->source_warehouse_id, 'to_warehouse_id' => (int)$proposal->warehouse_id,
                'quantity' => min((int)$proposal->shortage, (int)$proposal->surplus)];
        }
        $sourceProducts = Stock::query()
            ->select(['stocks.id', 'warehouse_id', 'product_id', 'quantity', 'quarantined_quantity'])
            ->whereColumn('quantity', '>', 'quarantined_quantity')
            ->withSum(['placements as inactive_quantity' => fn ($placements) => $placements
                ->whereHas('location', fn ($locations) => $locations->where('active', false))], 'quantity')
            ->get()
            ->filter(fn (Stock $stock) => $stock->available_quantity > (int) $stock->inactive_quantity)
            ->groupBy('warehouse_id')
            ->map(fn ($stocks) => $stocks->pluck('product_id')->map(fn ($id) => (string) $id)->values());

        $products = Product::with('defaultPackaging:id,product_id,name,pieces_per_package,gross_weight_kg,width_cm,depth_cm,height_cm,barcode,updated_at')
            ->orderBy('product_name')->get();

        return view('transfers.create', ['products' => $products, 'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
            'context' => $context, 'returnUrl' => TransferListContext::url($context), 'requestKey' => $requestKey, 'prefill' => $prefill,
            'storageLocations' => WarehouseLocation::pickerOptions($this->planDetails(...)), 'sourceProducts' => $sourceProducts]);
    }

    public function pickingSources(Request $request, SmartPicking $picking): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'product_id' => 'required|integer|exists:products,product_id',
            'quantity' => 'sometimes|required|integer|min:1|max:2147483647',
        ], [
            'from_warehouse_id.*' => 'Válassz érvényes forrásraktárt.',
            'product_id.*' => 'Válassz érvényes terméket.',
            'quantity.*' => 'A mennyiség 1 és 2 147 483 647 közötti egész szám legyen.',
        ]);

        return response()->json($picking->options(
            (int) $data['from_warehouse_id'],
            (int) $data['product_id'],
            isset($data['quantity']) ? (int) $data['quantity'] : null,
        ), 200, ['Cache-Control' => 'no-store']);
    }

    public function putawayRecommendations(Request $request, SmartPutaway $putaway): JsonResponse
    {
        $data = $request->validate([
            'to_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'product_id' => 'required|integer|exists:products,product_id',
            'cargo_weight_source' => 'sometimes|required|in:'.TransferCargoWeight::SOURCE_CALCULATED,
            'cargo_weight_packaging' => TransferCargoWeight::PACKAGING_REVISION_RULE,
        ], [
            'to_warehouse_id.*' => 'Válassz érvényes célraktárt.',
            'product_id.*' => 'Válassz érvényes terméket.',
            'cargo_weight_source.*' => 'Érvénytelen tömegforrás. Ellenőrizd a teljes tömeget.',
            'cargo_weight_packaging.*' => 'A csomagolás ellenőrzéséhez töltsd be az aktuális adatokat.',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $cargo = $request->only(['quantity', 'cargo_weight_kg', 'pallet_slots', 'dimensions_cm']);
        if (isset($data['cargo_weight_source']) || isset($data['cargo_weight_packaging'])) {
            $weightInput = Validator::make($cargo, [
                'quantity' => 'required|integer|min:1|max:2147483647',
                'cargo_weight_kg' => 'required|'.WarehouseCapacity::WEIGHT_RULE,
            ], [
                'quantity.*' => 'A mennyiség pozitív egész szám legyen, legfeljebb 2 147 483 647.',
                'cargo_weight_kg.required' => 'Erősítsd meg a teljes tömeget.',
                'cargo_weight_kg.*' => 'A rakomány tömege 0,001 és 1 000 000 kg közötti érték legyen, legfeljebb három tizedesjeggyel.',
            ])->validate();

            $packaging = $product->defaultPackaging;
            if (isset($data['cargo_weight_packaging'])) {
                TransferCargoWeight::assertPackagingRevision($packaging, $data['cargo_weight_packaging']);
            }
            if (isset($data['cargo_weight_source'])) {
                TransferCargoWeight::assertCalculated(
                    $packaging,
                    (int) $weightInput['quantity'],
                    WarehouseCapacity::grams($weightInput['cargo_weight_kg']),
                );
            }
        }

        $result = $putaway->recommend(Warehouse::findOrFail($data['to_warehouse_id']), $product, $cargo);

        $locations = WarehouseLocation::where('warehouse_id', $data['to_warehouse_id'])
            ->whereKey(array_column($result['recommendations'], 'location_id'))
            ->with(['system.zone', 'level'])->get()->keyBy('id');
        $recommendations = array_map(fn ($item) => $item + $this->planDetails($locations->get($item['location_id'])), $result['recommendations']);

        return response()->json(['recommendations' => $recommendations], 200, ['Cache-Control' => 'no-store']);
    }

    private function planDetails(?WarehouseLocation $location): array
    {
        if (!$location) return ['position_label' => '', 'plan_url' => null, 'positioned' => false];

        $system = $location->system;
        $parts = $system
            ? array_filter([$system->zone?->name, $system->name, $location->level ? $location->level->number.'. szint' : null,
                $location->bin_number ? $location->bin_number.($system->kind === 'floor' ? '. padlóhely' : '. rekesz') : null])
            : [$location->name];
        $target = $system ? ['system' => $system->id, 'bin' => $location->id] : ['location' => $location->id];

        return [
            'code' => $location->code,
            'name' => $location->name,
            'position_label' => implode(' · ', $parts),
            'short_position_label' => implode(' · ', $system ? array_replace($parts, [1 => $system->displayCode()]) : $parts),
            'search' => implode(' ', [$location->code, $location->name, $system?->displayCode(), ...$parts]),
            'plan_url' => route('warehouses.floor-plan.show', ['warehouse' => $location->warehouse_id] + $target).'#floor-inspector',
            'positioned' => ($system ?? $location)->floor_x !== null,
        ];
    }
    public function store(Request $request, InventoryService $inventory)
    {
        $rules = [
            'request_key' => 'required|uuid',
            'product_id' => 'required|integer|exists:products,product_id',
            'from_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'quantity' => 'required|integer|min:1|max:2147483647',
            'box_count' => 'sometimes|required|integer|min:1|max:2147483647',
            'replenishment_stock_id' => 'sometimes|required|integer|min:1',
            'cargo_weight_source' => 'sometimes|required|in:'.TransferCargoWeight::SOURCE_CALCULATED,
            'cargo_weight_packaging' => TransferCargoWeight::PACKAGING_REVISION_RULE,
            'cargo_weight_kg' => 'required_with:to_location_id,cargo_weight_source,cargo_weight_packaging|nullable|'.WarehouseCapacity::WEIGHT_RULE,
        ] + WarehouseCapacity::cargoRules();
        $messages = [
            'request_key.*' => 'Az űrlap azonosítója érvénytelen. Nyiss új átmozgatási űrlapot.',
            'product_id.*' => 'Válassz érvényes terméket.',
            'from_warehouse_id.*' => 'Válassz érvényes forrásraktárt.',
            'to_warehouse_id.*' => 'Válassz érvényes célraktárt.',
            'quantity.*' => 'A mennyiség 1 és 2 147 483 647 közötti egész szám legyen.',
            'box_count.*' => 'A dobozszám 1 és 2 147 483 647 közötti egész szám legyen.',
            'replenishment_stock_id.*' => 'Az utánpótlási javaslat érvénytelen.',
            'to_location_id.*' => 'Válassz érvényes célhelyet.',
            'cargo_weight_source.*' => 'Érvénytelen tömegforrás. Ellenőrizd a teljes tömeget.',
            'cargo_weight_packaging.*' => 'A csomagolás ellenőrzéséhez töltsd be az aktuális adatokat.',
            'cargo_weight_kg.required_with' => 'Add meg a rakomány teljes tömegét az indításhoz.',
            'cargo_weight_kg.*' => 'A teljes tömeg 0,001 és 1 000 000 kg közötti érték legyen, legfeljebb három tizedesjeggyel.',
            'pallet_slots.*' => 'A raklapok száma 1 és 1 000 000 közötti egész szám legyen.',
        ];

        $data = Validator::make($request->all(), $rules, $messages)->after(function ($validator) use ($request) {
            if ($validator->errors()->hasAny(['from_warehouse_id', 'to_warehouse_id'])) return;
            if ($request->integer('from_warehouse_id') === $request->integer('to_warehouse_id')) {
                $validator->errors()->add('to_warehouse_id', 'A forrásraktár és a célraktár nem lehet ugyanaz.');
            }
        })->validate();
        if (array_key_exists('source_allocations', $request->all())) {
            $data['source_allocations'] = $request->input('source_allocations');
        }
        $data['user_id'] = $request->user()->id;
        $transfer = $inventory->startTransfer($data, $data['request_key']);
        return redirect()->to(TransferListContext::url($request->input('context')))->with('success', 'Átmozgatás elindítva: '.$transfer->transfer_number.'.');
    }
    public function show(Request $request, InventoryTransfer $transfer)
    {
        $transfer->load(['product', 'fromWarehouse', 'toWarehouse', 'user', 'shipmentItem.stop.shipment', 'destinationLocation', 'sourceAllocations']);
        $context = $request->query('context', '');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        return view('transfers.show', ['transfer' => $transfer, 'context' => $context, 'returnUrl' => TransferListContext::url($context)]);
    }
    public function complete(Request $request, InventoryTransfer $transfer, InventoryService $inventory)
    {
        $inventory->finalizeTransfer($transfer, true);
        return redirect()->to(TransferListContext::url($request->input('context')))->with('success', 'Az átvétel rögzítve, a célraktár készlete frissült.');
    }
    public function cancel(Request $request, InventoryTransfer $transfer, InventoryService $inventory)
    {
        $needsPlacement = $inventory->finalizeTransfer($transfer, false);
        $message = 'Átmozgatás visszavonva. Az áru visszakerült a forrásraktárba.';
        if ($needsPlacement > 0) {
            $message .= ' '.number_format($needsPlacement, 0, ',', ' ').' db tárhely nélkül került vissza. Ezeket helyezd el egy alkalmas tárhelyen.';
        }

        return redirect()->to(TransferListContext::url($request->input('context')))->with('success', $message);
    }
}
