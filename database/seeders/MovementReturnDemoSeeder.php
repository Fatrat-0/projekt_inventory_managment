<?php

namespace Database\Seeders;

use App\Models\{Category, Partner, Product, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MovementReturnDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'worker@raktar.hu')->firstOrFail();
        DB::transaction(function () use ($user) {
            $key = fn ($index) => '978abd6e-f371-430d-941e-852db601000'.$index;
            if (DB::table('inventory_movement_requests')->where('user_id', $user->id)->where('request_key', $key(5))->whereNotNull('movement_id')->exists()) return;
            $supplier = Partner::firstOrCreate(['email' => 'return-supplier-demo@example.test'], ['name' => 'Bemutató beszállító – Visszáru', 'type' => 'supplier']);
            $customer = Partner::firstOrCreate(['email' => 'return-customer-demo@example.test'], ['name' => 'Bemutató vevő – Visszáru', 'type' => 'customer']);
            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Visszáru'], ['default_item_min_stock' => 0]);
            $product = Product::firstOrCreate(['sku' => 'DEMO-VISSZARU'], ['product_name' => 'Bemutató termék – részleges és teljes visszáru', 'price' => 100,
                'category_id' => $category->category_id, 'min_stock_override' => 0]);
            $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Bemutató raktár – Visszáru'], ['location' => 'Elkülönített bemutató adatok']);
            $base = ['user_id' => $user->id, 'product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id];
            $inventory = app(InventoryService::class);
            $receipt = $inventory->recordMovement($base + ['type' => 'in', 'quantity' => 12, 'partner_id' => $supplier->id, 'reference' => 'DEMO-VISSZA-001 · Beszállítás'], $key(1));
            $issue = $inventory->recordMovement($base + ['type' => 'out', 'quantity' => 5, 'partner_id' => $customer->id, 'reference' => 'DEMO-VISSZA-002 · Vevői kiadás'], $key(2));
            $inventory->recordReturn($receipt, ['user_id' => $user->id, 'quantity' => 3, 'return_reason' => 'Sérült csomagolás: részleges visszaküldés a beszállítónak.', 'reference' => 'DEMO-VISSZA-003'], $key(3));
            $inventory->recordReturn($issue, ['user_id' => $user->id, 'quantity' => 2, 'return_reason' => 'A vevő két darabot visszahozott.', 'reference' => 'DEMO-VISSZA-004'], $key(4));
            $inventory->recordReturn($issue, ['user_id' => $user->id, 'quantity' => 3, 'return_reason' => 'A maradék három darab visszavétele: teljes visszáru.', 'reference' => 'DEMO-VISSZA-005'], $key(5));
        });
    }
}
