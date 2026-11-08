<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use App\Services\WarehouseFloorPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseFloorPlanDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $transfer = DB::table('inventory_transfer_requests')
                ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_requests.transfer_id')
                ->where('request_key', WarehouseCapacityDemoSeeder::COMPLETION_KEY)
                ->first(['from_warehouse_id', 'to_warehouse_id']);
            if (!$transfer) return;

            $layouts = [
                $transfer->to_warehouse_id => ['R-01' => [2, 2, 10, 5], 'P-01' => [2, 10, 7, 2]],
                $transfer->from_warehouse_id => ['R-01' => [4, 2, 9, 5], 'P-01' => [13, 10, 6, 2]],
            ];
            foreach (Warehouse::whereKey(array_keys($layouts))->orderBy('warehouse_id')->lockForUpdate()->get() as $warehouse) {
                if ((int) $warehouse->floor_version !== 1) continue;
                $locations = $warehouse->storageLocations()->get()->keyBy('code');
                $positions = [];
                foreach ($layouts[$warehouse->getKey()] as $code => [$x, $y, $width, $height]) {
                    if (!$locations->has($code)) continue;
                    $positions[] = ['id' => $locations[$code]->id, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
                }
                app(WarehouseFloorPlan::class)->save($warehouse, ['version' => 1, 'columns' => 24, 'rows' => 16, 'positions' => $positions]);
            }
        }, 3);
    }
}
