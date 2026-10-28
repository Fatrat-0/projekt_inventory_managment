<?php

namespace Database\Seeders;

use App\Models\{Product, Shipment, User, Warehouse};
use App\Services\ShipmentService;
use Illuminate\Database\Seeder;

/** Additive draft only: never dispatches or changes stock. */
class ShipmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Shipment::where('reference', 'DEMO-RAKODAS-001 · Három megállós körjárat')->exists()) return;
        $user = User::where('email', 'worker@raktar.hu')->first();
        $warehouses = Warehouse::orderBy('warehouse_id')->limit(4)->get();
        $products = Product::whereIn('sku', ['GEP-FUR-18V', 'VED-KESZ-XL'])->orderBy('product_id')->get();
        if (!$user || $warehouses->count() < 4 || $products->count() < 2) throw new \RuntimeException('Először futtasd a DatabaseSeeder osztályt: a rakodási bemutatóhoz az alappéldák szükségesek.');
        app(ShipmentService::class)->save(['reference' => 'DEMO-RAKODAS-001 · Három megállós körjárat',
            'vehicle_label' => 'DEMO-001', 'origin_warehouse_id' => $warehouses[0]->warehouse_id,
            'note' => 'Tervezési példa. A megállók sorrendje kézzel módosítható; a felrakodási sorrend automatikusan fordított.',
            'stops' => $warehouses->skip(1)->values()->map(fn($warehouse, $index) => ['warehouse_id' => $warehouse->warehouse_id,
                'items' => [['product_id' => $products[0]->product_id, 'quantity' => $index + 1], ['product_id' => $products[1]->product_id, 'quantity' => 2]]])->all(),
        ], $user->id);
    }
}
