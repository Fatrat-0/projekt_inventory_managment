<?php

namespace Database\Seeders;

use App\Models\{Category, Partner, Product, User, Warehouse};
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Isolated example; completed examples and user-edited partner data are never reset. */
class PartnerMovementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'worker@raktar.hu')->firstOrFail();
        DB::transaction(function () use ($user) {
            $receiptKey = '8b44e365-59a6-4ef8-95b9-83200bcd0001';
            $issueKey = '8b44e365-59a6-4ef8-95b9-83200bcd0002';
            if (DB::table('inventory_movement_requests')->where('user_id', $user->id)->where('request_key', $issueKey)->whereNotNull('movement_id')->exists()) return;

            $partner = Partner::firstOrCreate(['email' => 'partner-demo@example.test'], [
                'name' => 'Bemutató partner – Vevő és beszállító', 'type' => 'both', 'phone' => '+36 1 555 0100',
                'contact_name' => 'Minta Anna', 'country' => 'Magyarország', 'postal_code' => '1117',
                'city' => 'Budapest', 'street_address' => 'Bemutató utca 12.', 'notes' => 'Áruátvétel hétköznap 8–14 óra között. Elkülönített bemutatóadat.',
            ]);
            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Partnerek'], ['default_item_min_stock' => 0]);
            $product = Product::firstOrCreate(['sku' => 'DEMO-PARTNER-CSAVAR'], [
                'product_name' => 'Bemutató csavar – partnerkapcsolat', 'category_id' => $category->category_id, 'price' => 100, 'min_stock_override' => 0,
            ]);
            $warehouse = Warehouse::firstOrCreate(['warehouse_name' => 'Bemutató raktár – Partnerek'], ['location' => 'Elkülönített bemutató adatok']);
            $base = ['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id, 'partner_id' => $partner->id];
            $service = app(InventoryService::class);
            $service->recordMovement($base + ['type' => 'in', 'quantity' => 20, 'reference' => 'DEMO-PARTNER-001 · Beszállítás'], $receiptKey);
            $service->recordMovement($base + ['type' => 'out', 'quantity' => 5, 'reference' => 'DEMO-PARTNER-002 · Kiszállítás'], $issueKey);
        });
    }
}
