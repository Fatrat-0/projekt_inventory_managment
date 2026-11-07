<?php

namespace App\Http\Controllers;

use App\Models\{InventoryTransfer, Product, Warehouse, WarehouseLocation};
use App\Queries\TransferListing;
use App\Queries\ReplenishmentSuggestions;
use App\Services\InventoryService;
use App\Services\WarehouseCapacity;
use App\Support\TransferListContext;
use Illuminate\Http\Request;
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
        return view('transfers.create', ['products' => Product::orderBy('product_name')->get(), 'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
            'context' => $context, 'returnUrl' => TransferListContext::url($context), 'requestKey' => $requestKey, 'prefill' => $prefill,
            'storageLocations' => WarehouseLocation::pickerOptions()]);
    }
    public function store(Request $request, InventoryService $inventory)
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'product_id' => 'required|integer|exists:products,product_id',
            'from_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,warehouse_id|different:from_warehouse_id', 'quantity' => 'required|integer|min:1|max:2147483647',
            'replenishment_stock_id' => 'sometimes|required|integer|min:1'] + WarehouseCapacity::cargoRules(),
            [], ['to_location_id' => 'cél tárhely', 'cargo_weight_kg' => 'teljes tömeg', 'pallet_slots' => 'raklapok száma']);
        $data['user_id'] = $request->user()->id;
        $transfer = $inventory->startTransfer($data, $data['request_key']);
        return redirect()->to(TransferListContext::url($request->input('context')))->with('success', 'Átmozgatás elindítva: '.$transfer->transfer_number.'.');
    }
    public function show(Request $request, InventoryTransfer $transfer)
    {
        $transfer->load(['product', 'fromWarehouse', 'toWarehouse', 'user', 'shipmentItem.stop.shipment', 'destinationLocation']);
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
        $inventory->finalizeTransfer($transfer, false);
        return redirect()->to(TransferListContext::url($request->input('context')))->with('success', 'Átmozgatás visszavonva. Az áru visszakerült a forrásraktárba.');
    }
}
