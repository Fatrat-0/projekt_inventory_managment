<?php

namespace Database\Seeders;

use App\Models\{Category, Product, ReturnQualityAction, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** A separate, additive example; repeating a completed demo never resets user edits. */
class ReturnQualityDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $key = fn (int $index) => '6c0cd922-956d-4867-9a0b-c1e2f309000'.$index;
            // Check before looking up the demo user or names: even renamed rows stay untouched.
            if (ReturnQualityAction::where('request_key', $key(5))->where('action', 'scrap')->exists()) return;
            $user = User::where('email', 'worker@raktar.hu')->firstOrFail();
            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Minősítés'], ['default_item_min_stock' => 8]);
            $product = Product::firstOrCreate(['sku' => 'DEMO-MINOSEG'], [
                'product_name' => 'Bemutató termék – visszáru minősítése', 'category_id' => $category->category_id,
                'price' => 100, 'min_stock_override' => 8,
            ]);
            $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Bemutató raktár – Minősítés'], ['location' => 'Különálló minősítési bemutatóadatok']);
            $inventory = app(InventoryService::class);
            $base = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id];
            $inventory->recordMovement($base + ['type' => 'in', 'quantity' => 12, 'reference' => 'DEMO-MINOSEG-001 · Beszállítás'], $key(1));
            $issue = $inventory->recordMovement($base + ['type' => 'out', 'quantity' => 8, 'reference' => 'DEMO-MINOSEG-002 · Vevői kiadás'], $key(2));
            $return = $inventory->recordReturn($issue, ['user_id' => $user->id, 'quantity' => 8,
                'return_reason' => 'A vevő nyolc darabot visszahozott; a termékek vizsgálatra várnak.', 'reference' => 'DEMO-MINOSEG-003'], $key(3));
            $inventory->processReturnQuality($return, ['user_id' => $user->id, 'action' => 'release', 'quantity' => 3,
                'reason' => 'Három darab sértetlen és működőképes; ellenőrzés után ismét kiadható.'], $key(4));
            $inventory->processReturnQuality($return, ['user_id' => $user->id, 'action' => 'scrap', 'quantity' => 2,
                'reason' => 'Két darab javíthatatlanul sérült; minősítés után selejtezve.'], $key(5));
        });
    }
}
