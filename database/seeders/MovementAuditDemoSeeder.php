<?php

namespace Database\Seeders;

use App\Models\{Category, Product, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Repeatable, additive demonstration data for audit balances and request replay. */
class MovementAuditDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'worker@raktar.hu')->first();
        if (!$user) {
            throw new \RuntimeException('Először futtasd az alap DatabaseSeeder osztályt: a worker@raktar.hu tesztfelhasználó szükséges.');
        }

        DB::transaction(function () use ($user) {
            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Audit'], ['default_item_min_stock' => 0]);
            $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Bemutató raktár – Audit'], ['location' => 'Elkülönített bemutató adatok']);
            $screw = Product::firstOrCreate(['sku' => 'DEMO-AUDIT-CSAVAR'], [
                'product_name' => 'Bemutató csavar – készletelőzmények', 'category_id' => $category->category_id,
                'price' => 100, 'min_stock_override' => 0,
            ]);
            $glove = Product::firstOrCreate(['sku' => 'DEMO-AUDIT-KESZTYU'], [
                'product_name' => 'Bemutató kesztyű – teljes kiadás', 'category_id' => $category->category_id,
                'price' => 1200, 'min_stock_override' => 0,
            ]);

            // Fixed request keys make this additive seeder safe to rerun.
            $examples = [
                [$screw, 'in', 20, 'DEMO-AUDIT-001 · Nyitó bevételezés', '85f413e6-031d-48be-9d47-8bc0ec2b0001'],
                [$screw, 'out', 5, 'DEMO-AUDIT-002 · Részleges kiadás', '85f413e6-031d-48be-9d47-8bc0ec2b0002'],
                [$screw, 'in', 8, 'DEMO-AUDIT-003 · Utánpótlás', '85f413e6-031d-48be-9d47-8bc0ec2b0003'],
                [$glove, 'in', 10, 'DEMO-AUDIT-004 · Nyitó bevételezés', '85f413e6-031d-48be-9d47-8bc0ec2b0004'],
                [$glove, 'out', 10, 'DEMO-AUDIT-005 · Teljes készlet kiadása', '85f413e6-031d-48be-9d47-8bc0ec2b0005'],
            ];
            $service = app(InventoryService::class);
            foreach ($examples as [$product, $type, $quantity, $reference, $key]) {
                $payload = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id,
                    'user_id' => $user->id, 'type' => $type, 'quantity' => $quantity, 'reference' => $reference];
                $service->recordMovement($payload, $key);
                // Simulate a successful request being sent again. No extra stock or history row.
                $service->recordMovement($payload, $key);
            }
        });
    }
}
