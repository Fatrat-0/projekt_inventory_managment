<?php

// Inspect only an existing browser-runner database. The handle cannot execute writes.
$runtime = realpath(getenv('INVENTORY_BROWSER_RUNTIME') ?: '');
$temp = realpath(sys_get_temp_dir());
if (!$runtime || dirname($runtime) !== $temp || !preg_match('/^inventory-browser-[A-Za-z0-9]+$/D', basename($runtime))) {
    throw new RuntimeException('A feladási teszt a böngészős futtató külön adatbázisát igényli.');
}
$path = realpath($runtime.DIRECTORY_SEPARATOR.'database.sqlite');
if (!$path || dirname($path) !== $runtime || basename($path) !== 'database.sqlite' || !is_file($path)) {
    throw new RuntimeException('A böngészős tesztadatbázis nem érhető el.');
}

$ids = array_map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), array_slice($argv, 1));
if (count($ids) !== 6 || in_array(false, $ids, true)) throw new RuntimeException('Érvénytelen feladási tesztazonosítók.');
[$shipmentId, $origin, $destinationA, $destinationB, $productA, $productB] = $ids;

$database = new SQLite3($path, SQLITE3_OPEN_READONLY);
$database->enableExceptions(true);
$database->busyTimeout(2000);

function rows(SQLite3 $database, string $sql, array $values): array
{
    $statement = $database->prepare($sql);
    foreach ($values as $index => $value) $statement->bindValue($index + 1, $value, SQLITE3_INTEGER);
    $result = $statement->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

    return $rows;
}

$state = [
    'shipment' => rows($database, 'SELECT status, version FROM shipments WHERE id = ?', [$shipmentId]),
    'stocks' => rows($database, 'SELECT warehouse_id, product_id, quantity FROM stocks WHERE warehouse_id IN (?, ?, ?) AND product_id IN (?, ?) ORDER BY warehouse_id, product_id', [$origin, $destinationA, $destinationB, $productA, $productB]),
    'tasks' => rows($database, 'SELECT tasks.transfer_intent_id, tasks.status, tasks.staged_quantity, tasks.dispatched_at
        FROM warehouse_transfer_tasks AS tasks JOIN shipment_intent_assignments AS assignments ON assignments.transfer_intent_id = tasks.transfer_intent_id
        WHERE assignments.shipment_id = ? AND assignments.detached_at IS NULL ORDER BY tasks.transfer_intent_id', [$shipmentId]),
    'dispatches' => rows($database, 'SELECT id, actor_id, dispatched_at, snapshot FROM shipment_dispatches WHERE shipment_id = ?', [$shipmentId]),
    'transit' => rows($database, 'SELECT transfer_intent_id, product_id, to_warehouse_id, shipment_stop_id, dispatched_quantity, remaining_quantity
        FROM shipment_transit_items WHERE shipment_id = ? ORDER BY transfer_intent_id', [$shipmentId]),
    'movements' => rows($database, 'SELECT movements.product_id, movements.warehouse_id, movements.quantity, movements.type, movements.stock_before, movements.stock_after
        FROM inventory_movements AS movements JOIN shipment_transit_items AS transit ON transit.id = movements.shipment_transit_item_id
        WHERE transit.shipment_id = ? ORDER BY movements.id', [$shipmentId]),
    'operations' => rows($database, "SELECT operation_key FROM shipment_plan_operations WHERE shipment_id = ? AND action = 'dispatch'", [$shipmentId]),
];
echo json_encode($state, JSON_THROW_ON_ERROR);
