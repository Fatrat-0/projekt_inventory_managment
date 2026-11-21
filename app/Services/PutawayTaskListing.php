<?php

namespace App\Services;

use App\Models\{InventoryMovement, Shipment, User};
use App\Policies\ShipmentPolicy;
use App\Support\{ListingNumber, ListingSort};
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PutawayTaskListing
{
    public const SORTS = ShipmentReceivingReader::PUTAWAY_SORTS + ['origin' => 'Eredet'];

    public function __construct(private ShipmentPolicy $policy) {}

    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $origin = $filters['origin'] ?? null;
        $shipmentOnly = isset($filters['receipt_id']) || isset($filters['shipment_id']);
        $manual = $this->manualRows();
        $shipment = $this->shipmentRows();
        $source = $shipmentOnly || $origin === 'shipment'
            ? $shipment : ($origin === 'manual' ? $manual : $shipment->unionAll($manual));
        $query = DB::query()->fromSub($source, 'tasks');
        if ($shipmentOnly && $origin === 'manual') $query->whereRaw('1 = 0');
        if ($actor->role !== 'admin') $query->whereIn('warehouse_id', $this->policy->receivingWarehouseIds($actor));
        foreach (['warehouse_id', 'receipt_id', 'shipment_id'] as $field) {
            if (isset($filters[$field])) $query->where($field, $filters[$field]);
        }
        $status = $filters['status'] ?? 'open';
        if ($status === 'open') $query->where('status', '<', 2);
        $ranks = ['pending' => 0, 'partial' => 1, 'completed' => 2];
        if (isset($ranks[$status])) $query->where('status', $ranks[$status]);
        if (!empty($filters['search'])) $this->search($query, trim($filters['search']));
        $levels = ListingSort::levels($filters, self::SORTS, 'id');
        foreach ($levels as ['field' => $field, 'direction' => $direction]) {
            $query->orderBy($field, $direction);
        }

        return $query->orderBy('id', $levels[0]['direction'])->orderBy('origin')
            ->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters)
            ->through(function (object $row) {
                $manual = $row->origin === 'manual';
                $number = $manual ? InventoryMovement::formatNumber($row->movement_id) : Shipment::formatNumber($row->shipment_id);

                return array_replace((array) $row, [
                    'status' => ['pending', 'partial', 'completed'][(int) $row->status],
                    'received_at' => Carbon::parse($row->received_at)->toISOString(),
                    'reference_number' => $number,
                    'origin_label' => $manual ? 'Kézi bevételezés' : 'Fuvarátvétel',
                    'url' => route($manual ? 'warehouse-putaway-pages.manual' : 'warehouse-putaway-pages.show', $row->id),
                    'reference_url' => $manual ? route('movements.show', $row->movement_id) : null,
                ]);
            });
    }

    private function shipmentRows(): Builder
    {
        return $this->rows('shipment_receipt_items', 'to_warehouse_id')
            ->join('shipment_receipts as receipt', 'receipt.id', '=', 'line.shipment_receipt_id')
            ->join('shipments as shipment', 'shipment.id', '=', 'receipt.shipment_id')
            ->join('transfer_intents as intent', 'intent.id', '=', 'line.transfer_intent_id')
            ->addSelect('receipt.id as receipt_id', 'shipment.id as shipment_id', 'line.shipment_stop_id', 'receipt.received_at',
                'shipment.reference', 'intent.reference as intent_reference')
            ->selectRaw("'shipment' AS origin, NULL AS movement_id");
    }

    private function manualRows(): Builder
    {
        return $this->rows('manual_receipt_batches', 'warehouse_id')
            ->join('inventory_movements as movement', 'movement.id', '=', 'line.movement_id')
            ->selectRaw('NULL AS receipt_id, NULL AS shipment_id, NULL AS shipment_stop_id')
            ->addSelect('line.created_at as received_at', 'movement.reference')
            ->selectRaw("NULL AS intent_reference, 'manual' AS origin, line.movement_id");
    }

    private function rows(string $table, string $warehouseField): Builder
    {
        // Keep the UNION columns in the same order regardless of their origin.
        return DB::table($table.' as line')->join('products', 'products.product_id', '=', 'line.product_id')
            ->join('warehouses', 'warehouses.warehouse_id', '=', 'line.'.$warehouseField)
            ->select('line.id', 'products.product_name', 'products.sku', 'warehouses.warehouse_id', 'warehouses.warehouse_name',
                'line.received_quantity', 'line.pending_putaway_quantity')
            ->selectRaw('line.received_quantity - line.pending_putaway_quantity AS placed_quantity')
            ->selectRaw('CASE WHEN line.pending_putaway_quantity = 0 THEN 2
                WHEN line.pending_putaway_quantity < line.received_quantity THEN 1 ELSE 0 END AS status');
    }

    private function search(Builder $query, string $search): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $query->where(function (Builder $match) use ($search, $pattern) {
            $match->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern]);
            foreach (['sku', 'warehouse_name', 'reference', 'intent_reference'] as $column) {
                $match->orWhereRaw("$column LIKE ? ESCAPE '!'", [$pattern]);
            }
            foreach (['MOZ' => 'movement_id', 'SZALL' => 'shipment_id'] as $prefix => $column) {
                if (($id = ListingNumber::id($search, $prefix)) !== null) $match->orWhere($column, $id);
            }
        });
    }
}
