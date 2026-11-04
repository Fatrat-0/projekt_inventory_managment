<?php

namespace App\Queries;

use App\Models\{InventoryMovement, Partner, Product, Warehouse};
use App\Support\ListingNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A work queue derived from immutable return receipts and quality actions. */
class ReturnQualityListing
{
    public const SORTS = ['created_at' => 'Beérkezés', 'product_name' => 'Termék', 'warehouse_name' => 'Raktár',
        'original_quantity' => 'Eredeti', 'released_quantity' => 'Felszabadítva', 'scrapped_quantity' => 'Selejtezve', 'remaining_quantity' => 'Hátralévő'];
    public const STATUSES = ['open' => 'Nyitott', 'waiting' => 'Várakozik', 'partial' => 'Részben minősítve', 'closed' => 'Lezárt', 'all' => 'Összes állapot'];
    private const PROCESSED = 'COALESCE(quality_totals.processed_quantity, 0)';
    private const REMAINING = 'CASE WHEN inventory_movements.quarantined_quantity > COALESCE(quality_totals.processed_quantity, 0) THEN inventory_movements.quarantined_quantity - COALESCE(quality_totals.processed_quantity, 0) ELSE 0 END';

    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'product_id' => 'nullable|integer|exists:products,product_id', 'warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'partner_id' => 'nullable|integer|exists:partners,id', 'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc', 'per_page' => ['nullable', 'integer', Rule::in([10,25,50,100])], 'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = ['search' => trim($data['search'] ?? ''), 'status' => $data['status'] ?? 'open',
            'product_id' => $data['product_id'] ?? null, 'warehouse_id' => $data['warehouse_id'] ?? null, 'partner_id' => $data['partner_id'] ?? null,
            'sort_by' => $data['sort_by'] ?? 'created_at', 'sort_dir' => $data['sort_dir'] ?? 'asc', 'per_page' => (int)($data['per_page'] ?? 25)];
    }

    public static function baseQuery(): Builder
    {
        $totals = DB::table('return_quality_actions')->select('return_movement_id')
            ->selectRaw("SUM(CASE WHEN action = 'release' THEN quantity ELSE 0 END) AS released_quantity")
            ->selectRaw("SUM(CASE WHEN action = 'scrap' THEN quantity ELSE 0 END) AS scrapped_quantity")
            ->selectRaw('SUM(quantity) AS processed_quantity')->groupBy('return_movement_id');
        return InventoryMovement::query()->leftJoinSub($totals, 'quality_totals', 'quality_totals.return_movement_id', '=', 'inventory_movements.id')
            ->whereNotNull('inventory_movements.return_of_id')->where('inventory_movements.type', 'in')->where('inventory_movements.quarantined_quantity', '>', 0)
            ->select('inventory_movements.*')->selectRaw('COALESCE(quality_totals.released_quantity, 0) AS released_quantity')
            ->selectRaw('COALESCE(quality_totals.scrapped_quantity, 0) AS scrapped_quantity')->selectRaw(self::REMAINING.' AS remaining_quantity');
    }

    public function query(bool $ignoreStatus = false): Builder
    {
        $f = $this->filters;
        $query = self::baseQuery()->with(['product', 'warehouse', 'partner']);
        foreach (['product_id', 'warehouse_id', 'partner_id'] as $key) {
            if ($f[$key] !== null) $query->where('inventory_movements.'.$key, $f[$key]);
        }
        if (!$ignoreStatus) {
            match ($f['status']) {
                'open' => $query->whereRaw('inventory_movements.quarantined_quantity > '.self::PROCESSED),
                'waiting' => $query->whereRaw(self::PROCESSED.' = 0'),
                'partial' => $query->whereRaw(self::PROCESSED.' > 0 AND inventory_movements.quarantined_quantity > '.self::PROCESSED),
                'closed' => $query->whereRaw('inventory_movements.quarantined_quantity <= '.self::PROCESSED),
                default => null,
            };
        }
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $id = ListingNumber::id($word, 'MOZ');
            $query->where(function (Builder $q) use ($pattern, $id) {
                $q->whereRaw("inventory_movements.reference LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("inventory_movements.return_reason LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('qualityActions', fn($a) => $a->whereRaw("reason LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereRaw("inventory_movements.partner_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('partner', fn($p) => $p->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('product', fn($p) => $p->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]));
                if ($id !== null) $q->orWhere('inventory_movements.id', $id);
            });
        }
        $sort = match ($f['sort_by']) {
            'product_name' => Product::select('product_name')->whereColumn('products.product_id', 'inventory_movements.product_id'),
            'warehouse_name' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'inventory_movements.warehouse_id'),
            'original_quantity' => 'inventory_movements.quarantined_quantity',
            'released_quantity', 'scrapped_quantity', 'remaining_quantity' => $f['sort_by'],
            default => 'inventory_movements.created_at',
        };
        return $query->orderBy($sort, $f['sort_dir'])->orderBy('inventory_movements.id', $f['sort_dir']);
    }

    public function summary(): array
    {
        $processed = self::PROCESSED;
        $open = 'inventory_movements.quarantined_quantity > '.$processed;
        $result = $this->query(true)->reorder()->withoutEagerLoads()->select([])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$open} THEN 1 ELSE 0 END), 0) AS open_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$processed} = 0 THEN 1 ELSE 0 END), 0) AS waiting_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$processed} > 0 AND {$open} THEN 1 ELSE 0 END), 0) AS partial_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN inventory_movements.quarantined_quantity <= {$processed} THEN 1 ELSE 0 END), 0) AS closed_count")
            ->selectRaw('COALESCE(SUM('.self::REMAINING.'), 0) AS pending_quantity')
            ->selectRaw("MIN(CASE WHEN {$open} THEN inventory_movements.created_at ELSE NULL END) AS oldest_at")->toBase()->first();
        $summary = [];
        foreach (['open_count', 'waiting_count', 'partial_count', 'closed_count', 'pending_quantity'] as $key) $summary[$key] = (int)$result->$key;
        $summary['oldest_at'] = $result->oldest_at ? Carbon::parse($result->oldest_at) : null;
        return $summary;
    }

    public static function pendingSummary(): array
    {
        $row = self::baseQuery()->whereRaw('inventory_movements.quarantined_quantity > '.self::PROCESSED)->select([])
            ->selectRaw('COUNT(*) AS returns_count, COALESCE(SUM('.self::REMAINING.'), 0) AS quantity, MIN(inventory_movements.created_at) AS oldest_at')->toBase()->first();
        return ['returns_count' => (int)$row->returns_count, 'quantity' => (int)$row->quantity, 'oldest_at' => $row->oldest_at ? Carbon::parse($row->oldest_at) : null];
    }

    public static function status(InventoryMovement $return): string
    {
        return (int)$return->remaining_quantity <= 0 ? 'closed' : ((int)$return->released_quantity + (int)$return->scrapped_quantity > 0 ? 'partial' : 'waiting');
    }

    public function labels(): array
    {
        $f = $this->filters;
        $labels = [];
        if ($f['search'] !== '') $labels['search'] = 'Keresés: '.$f['search'];
        if ($f['product_id']) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        if ($f['warehouse_id']) $labels['warehouse_id'] = 'Raktár: '.Warehouse::findOrFail($f['warehouse_id'])->warehouse_name;
        if ($f['partner_id']) $labels['partner_id'] = 'Partner: '.Partner::findOrFail($f['partner_id'])->name;
        return $labels;
    }
}
