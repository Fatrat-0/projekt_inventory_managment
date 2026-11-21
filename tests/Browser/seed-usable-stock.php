<?php

use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/environment.php';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'sqlite' || realpath(DB::connection()->getDatabaseName()) !== realpath($database)) {
    throw new RuntimeException('Opening stock fixtures require the isolated browser database.');
}

$data = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$actor = User::where('email', $data['actor_email'] ?? 'browser@example.test')->sole();
$movement = app(InventoryService::class)->recordMovement([
    'product_id' => $data['product_id'], 'warehouse_id' => $data['warehouse_id'],
    'quantity' => $data['quantity'], 'type' => 'in', 'user_id' => $actor->id,
    'reference' => $data['reference'] ?? null, 'partner_id' => $data['partner_id'] ?? null,
]);
echo $movement->getKey();
