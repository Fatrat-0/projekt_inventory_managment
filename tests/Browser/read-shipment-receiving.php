<?php

// Inspect only an existing browser test database, with a read-only connection.
$runtime = realpath(getenv('INVENTORY_BROWSER_RUNTIME') ?: '');
if (!$runtime || dirname($runtime) !== realpath(sys_get_temp_dir())
    || !preg_match('/^inventory-browser-[A-Za-z0-9]+$/D', basename($runtime))) {
    throw new RuntimeException('A fogadási próba a böngészős futtató elkülönített adatbázisát igényli.');
}
$path = realpath($runtime.DIRECTORY_SEPARATOR.'database.sqlite');
if (!$path || dirname($path) !== $runtime || !is_file($path) || basename($path) !== 'database.sqlite') {
    throw new RuntimeException('A böngészős tesztadatbázis nem érhető el.');
}
$id = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) throw new RuntimeException('Érvénytelen tesztfuvar-azonosító.');

$database = new SQLite3($path, SQLITE3_OPEN_READONLY);
$database->enableExceptions(true);
$database->busyTimeout(2000);

function receivingRows(SQLite3 $database, string $sql, int $id): array
{
    $statement = $database->prepare($sql);
    $statement->bindValue(':shipment', $id, SQLITE3_INTEGER);
    $result = $statement->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

    return $rows;
}

$state = [
    'shipment' => receivingRows($database, 'SELECT status, version FROM shipments WHERE id = :shipment', $id),
    'transit' => receivingRows($database, 'SELECT transit.id, transit.shipment_stop_id, transit.transfer_intent_id, transit.product_id, transit.to_warehouse_id,
        transit.dispatched_quantity, transit.remaining_quantity, COALESCE(stock.quantity, 0) AS physical_quantity,
        COALESCE(source_stock.quantity, 0) AS source_quantity
        FROM shipment_transit_items AS transit LEFT JOIN stocks AS stock ON stock.warehouse_id = transit.to_warehouse_id
        AND stock.product_id = transit.product_id LEFT JOIN stocks AS source_stock ON source_stock.warehouse_id = transit.from_warehouse_id
        AND source_stock.product_id = transit.product_id WHERE transit.shipment_id = :shipment ORDER BY transit.id', $id),
    'receiptItems' => receivingRows($database, 'SELECT line.id, line.shipment_receipt_id, line.product_id, line.transfer_intent_id,
        line.received_quantity, line.pending_putaway_quantity, line.note, receipt.operation_key
        FROM shipment_receipt_items AS line JOIN shipment_receipts AS receipt ON receipt.id = line.shipment_receipt_id
        WHERE receipt.shipment_id = :shipment ORDER BY line.id', $id),
    'placements' => receivingRows($database, 'SELECT event.shipment_receipt_item_id AS item_id, event.location_code, event.geometry_status,
        event.operation_key, location.id AS current_location_id, placement.quantity, placement.cargo_weight_grams, placement.pallet_slots
        FROM shipment_receipt_putaways AS event JOIN shipment_receipt_items AS line ON line.id = event.shipment_receipt_item_id
        JOIN shipment_receipts AS receipt ON receipt.id = line.shipment_receipt_id JOIN warehouse_placements AS placement ON placement.id = event.warehouse_placement_id
        JOIN warehouse_locations AS location ON location.id = placement.warehouse_location_id
        WHERE receipt.shipment_id = :shipment ORDER BY event.id', $id),
    'inMovements' => receivingRows($database, "SELECT movement.product_id, movement.warehouse_id, movement.quantity, movement.shipment_receipt_item_id
        FROM inventory_movements AS movement WHERE movement.type = 'in' AND EXISTS (
            SELECT 1 FROM shipment_transit_items AS transit WHERE transit.shipment_id = :shipment
            AND transit.product_id = movement.product_id AND transit.to_warehouse_id = movement.warehouse_id
        ) ORDER BY movement.id", $id),
];
echo json_encode($state, JSON_THROW_ON_ERROR);

