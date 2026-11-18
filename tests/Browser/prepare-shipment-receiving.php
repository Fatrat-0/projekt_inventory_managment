<?php

// Create a real dispatched shipment in the browser runner's disposable database.
use App\Models\{Category, Product, Shipment, TransferIntent, User, Vehicle, Warehouse};
use App\Services\{InventoryService, ShipmentPlanningService, TransferIntentService, TransferStockReservationService, VehicleRegistry, WarehouseStorageDesigner, WarehouseTransferTaskService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || DB::getDriverName() !== 'sqlite'
    || realpath(DB::connection()->getDatabaseName()) !== realpath($database)
    || !DB::getSchemaBuilder()->hasTable('shipment_receipt_putaways')) {
    throw new RuntimeException('A fogadási próba az előkészített, elkülönített böngészős adatbázist igényli.');
}

function receivingCommand(Shipment|TransferIntent $record): array
{
    return ['operation_key' => (string) Str::uuid(), 'version' => $record->refresh()->version];
}


function createReceivingTestIntent(User $admin, Warehouse $source, Warehouse $destination, Product $product, int $quantity): TransferIntent
{
    $intents = app(TransferIntentService::class);
    $response = $intents->create($admin, [
        'operation_key' => (string) Str::uuid(), 'product_id' => $product->getKey(), 'quantity' => $quantity,
        'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $destination->getKey(),
        'reason' => 'Átvételi munkafolyamat böngészőpróbája', 'shipping_preference' => 'groupable',
    ]);
    $intent = TransferIntent::findOrFail($response['intent']['id']);
    $intents->approve($admin, $intent, receivingCommand($intent));
    app(TransferStockReservationService::class)->reserve($admin, $intent, receivingCommand($intent));
    app(WarehouseTransferTaskService::class)->create($admin, $intent, receivingCommand($intent));

    return $intent;
}


$withOpenPicking = ($argv[1] ?? '') === '--open-picking';
$fixture = DB::transaction(function () use ($withOpenPicking) {
    $token = bin2hex(random_bytes(4));
    $admin = User::where('email', 'warehouse-admin@example.test')->firstOrFail();
    $source = Warehouse::create(['warehouse_name' => 'Központi szerelvényelosztó raktár · '.$token]);
    $pecs = Warehouse::create(['warehouse_name' => 'Pécs – Dél-dunántúli áruátvételi központ · '.$token]);
    $gyor = Warehouse::create(['warehouse_name' => 'Győr – Nyugati logisztikai központ · '.$token]);
    $receiver = function (Warehouse $warehouse, string $firstName, string $role) use ($token): string {
        $person = User::factory()->create([
            'name' => $firstName.' · fogadó raktár',
            'email' => Str::lower(Str::ascii($firstName)).'-'.$token.'@example.test',
            'password' => Hash::make('browser-test-password'), 'role' => 'worker',
        ]);
        $person->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $person->email;
    };

    $emailByStop = [
        $pecs->getKey() => $receiver($pecs, 'Pécs', 'receiving_operator'),
        $gyor->getKey() => $receiver($gyor, 'Győr', 'supervisor'),
    ];
    $dispatcher = $receiver($source, 'Feladó', 'dispatch_operator');
    $category = Category::firstOrCreate(['category_name' => 'Böngészőpróba – Áruátvétel']);
    $picking = app(WarehouseTransferTaskService::class);
    $inventory = app(InventoryService::class);
    $products = [];
    $weights = [];
    foreach ([
        ['screw', 'CSAVAR', 'Dobozolt horganyzott rögzítőcsavar hosszú ipari cikknévvel', 200, $pecs, 11000],
        ['machine', 'GEP', 'Ipari hajtóműegység és szerelési gépalkatrész', 40, $pecs, 400000],
        ['part', 'TARTOZEK', 'Acél tartószerelvény és rögzítő tartozék', 24, $gyor, 48000],
    ] as [$key, $skuPrefix, $name, $quantity, $target, $weight]) {
        $product = Product::create([
            'sku' => '5C-'.$skuPrefix.'-'.$token, 'product_name' => $name,
            'price' => 200, 'category_id' => $category->getKey(),
        ]);
        if ($key === 'screw') {
            $product->defaultPackaging()->create([
                'name' => 'Gyári kartondoboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
            ]);
        }
        $inventory->recordMovement([
            'product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'user_id' => $admin->id,
            'type' => 'in', 'quantity' => $quantity, 'reference' => 'Átvételi böngészőpróba nyitókészlete',
        ]);
        $intent = createReceivingTestIntent($admin, $source, $target, $product, $quantity);
        $picking->pick($admin, $intent, receivingCommand($intent) + [
            'quantity' => $quantity,
            'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $quantity]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A rakodótér mellett összeszámolt árumennyiség.'],
        ]);
        $products[$key] = [
            'id' => $product->getKey(), 'name' => $product->product_name, 'sku' => $product->sku,
            'intent' => $intent->id, 'intentReference' => $intent->reference, 'warehouse' => $target->getKey(),
        ];
        $weights[$intent->id] = $weight;
    }

    $vehicleResult = app(VehicleRegistry::class)->save($admin, [
        'operation_key' => (string) Str::uuid(), 'name' => 'Bemutató kéttengelyes tehergépkocsi',
        'registration_number' => 'PC-'.$token, 'payload_capacity_kg' => 1200, 'pallet_slots_capacity' => 6,
    ]);
    $vehicle = Vehicle::findOrFail($vehicleResult['vehicle']['id']);
    $planning = app(ShipmentPlanningService::class);
    $planned = $planning->create($admin, [
        'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $source->getKey(),
        'reference' => 'Áruátvételi bemutatófuvar · '.$token, 'intent_ids' => array_keys($weights),
        'planned_departure_at' => now()->subMinutes(10)->toIso8601String(),
    ]);
    $shipment = Shipment::findOrFail($planned['shipment']['id']);
    $planning->selectVehicle($admin, $shipment, receivingCommand($shipment) + ['vehicle_id' => $vehicle->id]);
    $cargo = null;
    foreach ($planned['assignments'] as $assignment) {
        $cargo = $planning->saveCargoItem($admin, $shipment, $assignment['id'], receivingCommand($shipment) + [
            'source' => 'manual', 'confirmed' => true, 'gross_weight_grams' => $weights[$assignment['transfer_intent_id']],
        ]);
    }
    $planning->confirmCargo($admin, $shipment, receivingCommand($shipment) + [
        'gross_weight_grams' => array_sum($weights), 'pallet_slots' => 1, 'basis_revision' => $cargo['cargo']['basis_revision'],
    ]);
    $planning->dispatch($admin, $shipment, receivingCommand($shipment) + ['confirmed' => true]);

    $rack = app(WarehouseStorageDesigner::class)->create($pecs->refresh(), [
        'version' => $pecs->floor_version, 'kind' => 'rack', 'name' => 'A állvány',
        'code' => 'A', 'zone' => 'Csavarzóna', 'width' => 4, 'height' => 2, 'rack_height' => 2,
        'x' => 2, 'y' => 2, 'levels' => 1, 'bins' => 2, 'bin_weight' => 100, 'level_weight' => 200, 'system_weight' => 200,
    ]);
    $slots = [];
    $locations = $pecs->storageSystems()->findOrFail($rack['id'])->locations()->orderBy('bin_number')->get();
    foreach ($locations as $index => $location) {
        $code = 'A-0'.($index + 1);
        $location->update(['code' => $code]);
        $slots[$code] = $location->id;
    }
    foreach ([
        [$pecs, 'GEP-01', 'Gépalkatrész-tároló', 500000, true, ['width_cm' => 150, 'depth_cm' => 100, 'height_cm' => 150]],
        [$pecs, 'INAKTIV', 'Karbantartás alatt', 500000, false, []],
        [$pecs, 'KIS-01', 'Kis teherbírású rekesz', 1000, true, []],
        [$gyor, 'GY-01', 'Győri tartozékrekesz', 100000, true, []],
    ] as [$warehouse, $code, $name, $limit, $active, $size]) {
        $slot = $warehouse->storageLocations()->create([
            'code' => $code, 'name' => $name, 'kind' => 'shelf', 'max_weight_grams' => $limit, 'active' => $active,
        ]);
        $slot->forceFill($size)->save();
        $slots[$code] = $slot->id;
    }

    $pickingIntent = null;
    if ($withOpenPicking) {
        User::where('email', $emailByStop[$pecs->getKey()])->sole()->warehouseMemberships()->create([
            'warehouse_id' => $source->getKey(), 'role' => 'dispatch_operator',
        ]);
        $product = Product::create([
            'sku' => '5C-KITAROLAS-'.$token, 'product_name' => 'Hosszú nevű pótalkatrész a kitárolási próba számára',
            'price' => 200, 'category_id' => $category->getKey(),
        ]);
        $inventory->recordMovement([
            'product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'user_id' => $admin->id,
            'type' => 'in', 'quantity' => 9, 'reference' => 'Nyitott kitárolási próba készlet',
        ]);
        $pickingIntent = createReceivingTestIntent($admin, $source, $pecs, $product, 9)->id;
        $code = 'BEMUTATO-FUV-HOSSZU-REKESZ-01234';
        $slot = $pecs->storageLocations()->create([
            'code' => $code, 'name' => 'Önálló, alaprajzi pozíció nélküli polc',
            'kind' => 'shelf', 'max_weight_grams' => 60000,
        ]);
        $slot->forceFill(['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();
        $slots[$code] = $slot->id;
    }


    $stops = $shipment->stops()->get()->keyBy('warehouse_id')->map(fn ($stop) => [
        'id' => $stop->id, 'warehouseId' => $stop->warehouse_id, 'name' => $stop->warehouse->warehouse_name,
        'email' => $emailByStop[$stop->warehouse_id], 'position' => $stop->position,
    ]);
    return [
        'id' => $shipment->id, 'number' => $shipment->shipment_number, 'reference' => $shipment->reference,
        'pecs' => $stops[$pecs->getKey()], 'gyor' => $stops[$gyor->getKey()], 'source' => $source->getKey(),
        'products' => $products, 'slots' => $slots, 'rack' => $rack['id'], 'dispatcher' => $dispatcher,
        'pickingIntent' => $pickingIntent,
    ];
});
echo json_encode($fixture, JSON_THROW_ON_ERROR);

