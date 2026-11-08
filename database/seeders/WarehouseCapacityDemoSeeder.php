<?php

namespace Database\Seeders;

use App\Models\{Category, Product, User, Warehouse};
use App\Services\{InventoryService, WarehouseCapacity};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseCapacityDemoSeeder extends Seeder
{
    public const COMPLETION_KEY = '88c0e445-0ff1-4773-9874-91605de1de02';

    public function run(): void
    {
        DB::transaction(function () {
            $completion = DB::table('inventory_transfer_requests')->where('request_key', self::COMPLETION_KEY)->whereNotNull('transfer_id');
            if ((clone $completion)->exists()) return;

            $user = User::where('email', 'worker@raktar.hu')->lockForUpdate()->first();
            if (!$user) throw new \RuntimeException('Először futtasd az alap DatabaseSeeder osztályt: a kapacitásbemutatóhoz a worker@raktar.hu tesztfelhasználó szükséges.');
            // Recheck after taking the lock so overlapping seed runs cannot create a second demo.
            if ($completion->where('user_id', $user->id)->lockForUpdate()->first(['id'])) return;

            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Kapacitás', 'parent_id' => null]);
            $product = Product::create(['sku' => 'DEMO-KAP-001', 'product_name' => 'Bemutató rögzítőkészlet (doboz)',
                'category_id' => $category->category_id, 'price' => 2400]);

            $central = Warehouse::create(['warehouse_name' => 'Bemutató raktár – Központi kapacitás', 'location' => 'Kapacitáskezelési bemutató · Budapest']);
            $regional = Warehouse::create(['warehouse_name' => 'Bemutató raktár – Regionális kapacitás', 'location' => 'Kapacitáskezelési bemutató · Pécs']);
            $capacity = app(WarehouseCapacity::class);
            $inventory = app(InventoryService::class);
            $item = ['product_id' => $product->product_id, 'user_id' => $user->id];

            $centralPallets = $capacity->saveLocation($central, ['code' => 'R-01', 'name' => 'Központi raklapzóna',
                'kind' => 'pallet', 'max_weight_kg' => 1800, 'max_pallet_slots' => 8]);
            $centralShelf = $capacity->saveLocation($central, ['code' => 'P-01', 'name' => 'Komissiózó polc', 'kind' => 'shelf', 'max_weight_kg' => 300]);
            $regionalPallets = $capacity->saveLocation($regional, ['code' => 'R-01', 'name' => 'Regionális raklapzóna',
                'kind' => 'pallet', 'max_weight_kg' => 1600, 'max_pallet_slots' => 10]);
            $regionalShelf = $capacity->saveLocation($regional, ['code' => 'P-01', 'name' => 'Kiadási polc', 'kind' => 'shelf', 'max_weight_kg' => 300]);

            $inventory->recordMovement($item + ['warehouse_id' => $central->warehouse_id, 'type' => 'in', 'quantity' => 700, 'reference' => 'DEMO-KAP-001 · Központi bevételezés']);
            $inventory->recordMovement($item + ['warehouse_id' => $regional->warehouse_id, 'type' => 'in', 'quantity' => 450, 'reference' => 'DEMO-KAP-002 · Regionális bevételezés']);
            $capacity->place($centralPallets, $item + ['quantity' => 360, 'cargo_weight_kg' => 1200, 'pallet_slots' => 6]);
            $capacity->place($centralShelf, $item + ['quantity' => 50, 'cargo_weight_kg' => 150]);
            $capacity->place($regionalPallets, $item + ['quantity' => 200, 'cargo_weight_kg' => 680, 'pallet_slots' => 4]);
            $capacity->place($regionalShelf, $item + ['quantity' => 35, 'cargo_weight_kg' => 105]);

            $issue = $inventory->recordMovement($item + ['warehouse_id' => $regional->warehouse_id, 'type' => 'out',
                'quantity' => 60, 'reference' => 'DEMO-KAP-003 · Regionális kiadás']);
            $inventory->recordReturn($issue, ['user_id' => $user->id, 'quantity' => 60, 'reference' => 'DEMO-KAP-004',
                'return_reason' => 'A visszaérkezett dobozok csomagolása sérült; átvizsgálásra várnak.']);

            $inventory->startTransfer($item + ['from_warehouse_id' => $central->warehouse_id, 'to_warehouse_id' => $regional->warehouse_id,
                'quantity' => 100, 'to_location_id' => $regionalPallets->id, 'cargo_weight_kg' => 340, 'pallet_slots' => 2]);
            $inventory->startTransfer($item + ['from_warehouse_id' => $regional->warehouse_id, 'to_warehouse_id' => $central->warehouse_id,
                'quantity' => 70, 'to_location_id' => $centralPallets->id, 'cargo_weight_kg' => 250, 'pallet_slots' => 2], self::COMPLETION_KEY);
        }, 3);
    }
}
