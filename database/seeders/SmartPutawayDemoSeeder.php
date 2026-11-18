<?php

namespace Database\Seeders;

use App\Models\{Category, Product, User, Warehouse};
use App\Services\{InventoryService, WarehouseCapacity};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SmartPutawayDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $user = User::where('email', 'worker@raktar.hu')->lockForUpdate()->first();
            $demo = DB::table('inventory_transfer_requests')
                ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_requests.transfer_id')
                ->where('request_key', WarehouseCapacityDemoSeeder::COMPLETION_KEY)
                ->first(['from_warehouse_id', 'to_warehouse_id']);

            if (!$user || !$demo) {
                throw new \RuntimeException('A betárolási bemutatóhoz először futtasd a DatabaseSeeder osztályt.');
            }

            $examples = [
                'DEMO-SP-001' => ['Bemutató alátét – ismert doboztömeg', '5.500'],
                'DEMO-SP-002' => ['Bemutató alátét – ismeretlen doboztömeg', null],
            ];
            $category = Category::firstOrCreate(
                ['category_name' => 'Bemutató termékek – Betárolás', 'parent_id' => null],
                ['default_item_min_stock' => 0],
            );
            $inventory = app(InventoryService::class);

            foreach ($examples as $sku => [$name, $grossWeight]) {
                // Keep edited packaging and used demo stock on repeated runs.
                if (Product::where('sku', $sku)->lockForUpdate()->first(['product_id'])) continue;

                $product = Product::create([
                    'sku' => $sku,
                    'product_name' => $name,
                    'category_id' => $category->category_id,
                    'price' => 35,
                    'min_stock_override' => 0,
                ]);
                $product->defaultPackaging()->create([
                    'name' => 'Karton',
                    'pieces_per_package' => 100,
                    'gross_weight_kg' => $grossWeight,
                    'width_cm' => 30,
                    'depth_cm' => 20,
                    'height_cm' => 15,
                ]);

                // The capacity demo completion transfer travels from the regional warehouse to the central one.
                $inventory->recordMovement([
                    'product_id' => $product->product_id,
                    'user_id' => $user->id,
                    'warehouse_id' => $demo->to_warehouse_id,
                    'type' => 'in',
                    'quantity' => 325,
                    'reference' => $sku.' · Nyitó bevételezés',
                ]);
            }

            $regional = Warehouse::findOrFail($demo->from_warehouse_id);
            if (!$regional->storageLocations()->where('code', 'SP-01')->lockForUpdate()->first(['id'])) {
                $shelf = app(WarehouseCapacity::class)->saveLocation($regional, [
                    'code' => 'SP-01', 'name' => 'Betárolási próbapolc', 'kind' => 'shelf', 'max_weight_kg' => 25,
                ]);
                $shelf->forceFill(['width_cm' => 100, 'depth_cm' => 60, 'height_cm' => 50])->save();
            }
        }, 3);
    }
}
