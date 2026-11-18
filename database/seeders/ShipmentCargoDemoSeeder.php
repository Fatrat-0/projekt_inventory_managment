<?php

namespace Database\Seeders;

use App\Models\{Category, Product, Shipment, ShipmentPlanOperation, TransferIntent, User, Vehicle, Warehouse};
use App\Services\{InventoryService, ShipmentPlanningService, TransferCargoWeight, TransferIntentService, TransferStockReservationService, VehicleRegistry, WarehouseCapacity, WarehouseTransferTaskService};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShipmentCargoDemoSeeder extends Seeder
{
    // The first plan's immutable creation audit marks the initial booking and keeps the original destination IDs.
    private const FIRST_PLAN_KEY = '49fb2d2e-e7c2-4371-9ba8-6d24a5250ad1';

    public function run(): void
    {
        DB::transaction(function () {
            $admin = User::where('email', 'admin@raktar.hu')->lockForUpdate()->first();
            $demo = ShipmentPlanOperation::where('operation_key', self::FIRST_PLAN_KEY)->sharedLock()->first(['result']);
            if ($demo) {
                $stops = collect($demo->result['stops'])->keyBy('position');
                $this->addPutawayLocations(Warehouse::findOrFail($stops[1]['warehouse_id']), Warehouse::findOrFail($stops[2]['warehouse_id']));

                return;
            }

            if (!$admin) {
                throw new RuntimeException('A fuvarbemutatóhoz először futtasd a teljes DatabaseSeeder osztályt.');
            }
            if ($admin->role !== 'admin') {
                $admin = User::where('role', 'admin')->orderBy('id')->sharedLock()->first();
            }
            if (!$admin) throw new RuntimeException('A fuvarbemutató első betöltéséhez aktív adminisztrátori fiók szükséges.');

            $source = Warehouse::firstOrCreate(['warehouse_name' => 'Bemutató raktár – Fuvar-előkészítés'], [
                'location' => '1117 Budapest, bemutató kiadási terület',
            ]);
            $pecs = Warehouse::where('warehouse_name', 'Déli Regionális Telephely (Pécs)')->firstOrFail();
            $gyor = Warehouse::where('warehouse_name', 'Nyugati Logisztikai Központ (Győr)')->firstOrFail();
            $this->addMemberships($source);

            $category = Category::firstOrCreate(['category_name' => 'Bemutató termékek – Fuvar-előkészítés', 'parent_id' => null]);
            $screws = $this->product($admin, $source, $category, 'DEMO-FUVAR-CSAVAR', 'Dobozolt rögzítőcsavar', 120, 450);
            $machines = $this->product($admin, $source, $category, 'DEMO-FUVAR-GEP', 'Ipari hajtóműegység', 38000, 180);
            $parts = $this->product($admin, $source, $category, 'DEMO-FUVAR-ALKATRESZ', 'Acél tartószerelvény', 24000, 40);
            $screws->defaultPackaging()->firstOrCreate([], [
                'name' => 'Gyári kartondoboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
            ]);

            $large = $this->vehicle($admin, 'Bemutató teherautó · 1200 kg / 6 hely', 'DEMO-4A-1', 1200, 6);
            $small = $this->vehicle($admin, 'Bemutató furgon · 800 kg / 3 hely', 'DEMO-4A-2', 800, 3);

            $this->plan($admin, $source, $large, 'Kapacitás rendben',
                'Kezdőállapot: három összekészített igény, két megálló. 975 kg igazolt tételtömeg és 25 kg közös rakodási anyag. A járművön 1000 kg és 4 raklaphely szükséges, a korlát 1200 kg és 6 hely.',
                [[$screws, $pecs, 200, 200, null], [$machines, $pecs, 40, 40, 400000], [$parts, $gyor, 24, 24, 564000]],
                ['gross_weight_grams' => 1000000, 'pallet_slots' => 4, 'shared_materials_weight_grams' => 25000], self::FIRST_PLAN_KEY);

            $this->plan($admin, $source, $small, 'Járműkapacitás túllépve',
                'Kezdőállapot: a kitárolás kész. Az 1000 kg-os rakomány 4 helyet igényel, a kiválasztott furgon 800 kg-ot és 3 raklaphelyet enged.',
                [[$machines, $gyor, 100, 100, 1000000]], ['gross_weight_grams' => 1000000, 'pallet_slots' => 4]);

            $this->plan($admin, $source, $large, 'Kitárolás folyamatban',
                'Kezdőállapot: 20 darabból 10 összekészítve. A 200 kg-os, raklaphelyet nem igénylő rakomány adatai megerősítve. A kitárolás a Raktári feladatokban folytatható.',
                [[$machines, $pecs, 20, 10, 200000]], ['gross_weight_grams' => 200000, 'pallet_slots' => 0]);

            $this->plan($admin, $source, $large, 'Rakományadat hiányzik',
                'Kezdőállapot: 200 darab összekészítve. Két 5,5 kg-os dobozból számolt javaslat 11 kg. A tétel és a teljes rakomány adatai megerősítésre várnak.',
                [[$screws, $gyor, 200, 200, null]], null);
            $this->addPutawayLocations($pecs, $gyor);
        }, 3);
    }

    private function addPutawayLocations(Warehouse $pecs, Warehouse $gyor): void
    {
        $capacity = app(WarehouseCapacity::class);
        $capacity->lockWarehouses([$pecs->getKey(), $gyor->getKey()]);
        foreach ([
            [$pecs, 'FUV-A-01', 'Bemutató csavarpolc 1', 100, 0, 100, 80, 70],
            [$pecs, 'FUV-A-02', 'Bemutató csavarpolc 2', 100, 0, 100, 80, 70],
            [$pecs, 'FUV-GEP-01', 'Bemutató gépalkatrész-raklaphely', 650, 2, 240, 120, 150],
            [$gyor, 'FUV-GY-01', 'Bemutató tartozék-raklaphely', 1800, 2, 240, 120, 150],
            [$gyor, 'FUV-GY-POLC', 'Bemutató dobozosáru-polc', 100, 0, 100, 80, 70],
        ] as [$warehouse, $code, $name, $weightKg, $slots, $width, $depth, $height]) {
            if ($warehouse->storageLocations()->where('code', $code)->sharedLock()->first(['id'])) continue;

            $location = $capacity->saveLocation($warehouse, [
                'code' => $code, 'name' => $name, 'kind' => $slots > 0 ? 'pallet' : 'shelf',
                'max_weight_kg' => $weightKg, 'max_pallet_slots' => $slots,
            ]);
            $location->forceFill(['width_cm' => $width, 'depth_cm' => $depth, 'height_cm' => $height])->save();
        }
    }

    private function addMemberships(Warehouse $source): void
    {
        foreach ([['manager@raktar.hu', 'manager', 'supervisor'], ['worker@raktar.hu', 'worker', 'dispatch_operator']] as [$email, $role, $membership]) {
            $user = User::where('email', $email)->where('role', $role)->first();
            $user?->warehouseMemberships()->firstOrCreate(['warehouse_id' => $source->getKey()], ['role' => $membership]);
        }
    }

    private function product(User $admin, Warehouse $source, Category $category, string $sku, string $name, int $price, int $quantity): Product
    {
        $product = Product::firstOrCreate(['sku' => $sku], [
            'product_name' => $name, 'price' => $price, 'category_id' => $category->getKey(),
        ]);
        app(InventoryService::class)->recordMovement([
            'product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'user_id' => $admin->id,
            'type' => 'in', 'quantity' => $quantity, 'reference' => '4/A bemutató nyitókészlet',
        ]);

        return $product;
    }

    private function vehicle(User $admin, string $name, string $plate, int $payloadKg, int $slots): Vehicle
    {
        $result = app(VehicleRegistry::class)->save($admin, [
            'operation_key' => (string) Str::uuid(), 'name' => $name, 'registration_number' => $plate,
            'payload_capacity_kg' => $payloadKg, 'pallet_slots_capacity' => $slots,
        ]);

        return Vehicle::findOrFail($result['vehicle']['id']);
    }

    /** Each line contains the product, destination, requested and picked pieces, and manual weight if used. */
    private function plan(User $admin, Warehouse $source, Vehicle $vehicle, string $name, string $note, array $lines, ?array $totals, ?string $key = null): void
    {
        $weights = [];
        foreach ($lines as [$product, $target, $quantity, $picked, $weight]) {
            $intent = $this->prepare($admin, $source, $target, $product, $quantity, $picked);
            $weights[$intent->id] = $weight === null ? [
                'source' => 'calculated', 'confirmed' => $totals !== null,
                'packaging_revision' => TransferCargoWeight::packagingRevision($product->defaultPackaging()->firstOrFail()),
            ] : ['source' => 'manual', 'confirmed' => true, 'gross_weight_grams' => $weight];
        }

        $planning = app(ShipmentPlanningService::class);
        $result = $planning->create($admin, [
            'operation_key' => $key ?? (string) Str::uuid(), 'origin_warehouse_id' => $source->getKey(),
            'reference' => '4/A bemutató – '.$name, 'intent_ids' => array_keys($weights),
            'planned_departure_at' => now('Europe/Budapest')->addDay()->setTime(8, 0)->toIso8601String(),
            'vehicle_label' => $vehicle->registration_number, 'note' => $note,
        ]);
        $plan = Shipment::findOrFail($result['shipment']['id']);
        $planning->selectVehicle($admin, $plan, $this->command($plan) + ['vehicle_id' => $vehicle->id]);
        foreach ($result['assignments'] as $assignment) {
            $latest = $planning->saveCargoItem($admin, $plan, $assignment['id'], $this->command($plan) + $weights[$assignment['transfer_intent_id']]);
        }
        if ($totals !== null) {
            $planning->confirmCargo($admin, $plan, $this->command($plan) + $totals + ['basis_revision' => $latest['cargo']['basis_revision']]);
        }
    }

    private function prepare(User $admin, Warehouse $source, Warehouse $target, Product $product, int $quantity, int $picked): TransferIntent
    {
        $intents = app(TransferIntentService::class);
        $created = $intents->create($admin, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $product->getKey(), 'quantity' => $quantity,
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(),
            'shipping_preference' => 'groupable', 'reason' => '4/A fuvar-előkészítési bemutató',
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $intents->approve($admin, $intent, $this->command($intent));
        app(TransferStockReservationService::class)->reserve($admin, $intent, $this->command($intent));
        $picking = app(WarehouseTransferTaskService::class);
        $picking->create($admin, $intent, $this->command($intent));
        $picking->pick($admin, $intent, $this->command($intent) + [
            'quantity' => $picked,
            'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $picked]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A bemutató kiadási területen összeszámolva.'],
        ]);

        return $intent;
    }

    private function command(Shipment|TransferIntent $record): array
    {
        return ['operation_key' => (string) Str::uuid(), 'version' => $record->refresh()->version];
    }
}
