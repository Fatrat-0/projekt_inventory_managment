<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_stops', fn (Blueprint $table) => $table->unsignedInteger('receiving_version')->default(1));
        Schema::table('shipment_transit_items', fn (Blueprint $table) => $table->unique(
            ['id', 'shipment_stop_id', 'transfer_intent_id', 'to_warehouse_id', 'product_id'], 'shipment_transit_receipt_identity_unique',
        ));

        Schema::create('shipment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id');
            $table->foreignId('shipment_stop_id');
            $table->foreignId('to_warehouse_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('received_at');
            $table->string('note', 500)->nullable();
            $table->uuid('operation_key')->unique();
            $table->char('request_hash', 64);
            $table->json('result')->nullable();
            $table->unique(['id', 'shipment_stop_id', 'to_warehouse_id'], 'shipment_receipt_stop_identity_unique');
            $table->index(['shipment_stop_id', 'id'], 'shipment_receipt_stop_history_index');
            $table->foreign(['shipment_id', 'shipment_stop_id'], 'shipment_receipt_plan_stop_fk')
                ->references(['shipment_id', 'id'])->on('shipment_stops')->restrictOnDelete();
            $table->foreign(['shipment_stop_id', 'to_warehouse_id'], 'shipment_receipt_stop_warehouse_fk')
                ->references(['id', 'warehouse_id'])->on('shipment_stops')->restrictOnDelete();
        });

        Schema::create('shipment_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_receipt_id');
            $table->foreignId('shipment_transit_item_id');
            $table->foreignId('shipment_stop_id');
            $table->foreignId('transfer_intent_id');
            $table->foreignId('to_warehouse_id');
            $table->foreignId('product_id');
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('pending_putaway_quantity');
            $table->string('note', 500)->nullable();
            $table->unique(['shipment_receipt_id', 'shipment_transit_item_id'], 'shipment_receipt_transit_once');
            $table->unique(['id', 'to_warehouse_id', 'product_id'], 'shipment_receipt_item_stock_identity_unique');
            $table->index(['to_warehouse_id', 'product_id', 'pending_putaway_quantity'], 'shipment_receipt_item_pending_index');
            $table->index(['shipment_transit_item_id'], 'shipment_receipt_item_transit_index');
            $table->foreign(['shipment_receipt_id', 'shipment_stop_id', 'to_warehouse_id'], 'shipment_receipt_item_stop_fk')
                ->references(['id', 'shipment_stop_id', 'to_warehouse_id'])->on('shipment_receipts')->restrictOnDelete();
            $table->foreign(['shipment_transit_item_id', 'shipment_stop_id', 'transfer_intent_id', 'to_warehouse_id', 'product_id'], 'shipment_receipt_item_transit_fk')
                ->references(['id', 'shipment_stop_id', 'transfer_intent_id', 'to_warehouse_id', 'product_id'])
                ->on('shipment_transit_items')->restrictOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('shipment_receipt_item_id')->nullable()->unique('movement_shipment_receipt_item_unique');
            $table->foreign(['shipment_receipt_item_id', 'warehouse_id', 'product_id'], 'movement_shipment_receipt_item_fk')
                ->references(['id', 'to_warehouse_id', 'product_id'])->on('shipment_receipt_items')->restrictOnDelete();
        });

        $this->protectReceipts();
        $this->protectReceiptItems();
        $this->protectReceiptMovements();
    }

    public function down(): void
    {
        if (DB::table('shipment_receipts')->exists()) {
            throw new RuntimeException('Rögzített fuvarátvételekkel a migráció nem vonható vissza.');
        }
        foreach (['validate_insert', 'no_update', 'no_delete', 'no_replacement'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS movement_receipt_'.$suffix);
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign($this->sqlite() ? ['shipment_receipt_item_id', 'warehouse_id', 'product_id'] : 'movement_shipment_receipt_item_fk');
            $table->dropUnique('movement_shipment_receipt_item_unique');
            if (!$this->sqlite()) $table->dropIndex('movement_shipment_receipt_item_fk');
        });
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropColumn('shipment_receipt_item_id'));
        Schema::dropIfExists('shipment_receipt_items');
        Schema::dropIfExists('shipment_receipts');
        Schema::table('shipment_transit_items', fn (Blueprint $table) => $table->dropUnique('shipment_transit_receipt_identity_unique'));
        Schema::table('shipment_stops', fn (Blueprint $table) => $table->dropColumn('receiving_version'));
    }

    private function protectReceipts(): void
    {
        $message = 'A rögzített fuvarátvétel nem módosítható és nem törölhető.';
        $identity = $this->changed(['id', 'shipment_id', 'shipment_stop_id', 'to_warehouse_id',
            'actor_id', 'received_at', 'note', 'operation_key', 'request_hash']);
        $this->guard('shipment_receipts', 'shipment_receipt_seal_result', 'UPDATE', "OLD.result IS NOT NULL OR NEW.result IS NULL OR $identity", $message);
        $this->guard('shipment_receipts', 'shipment_receipt_no_delete', 'DELETE', '1 = 1', $message);
        if ($this->sqlite()) {
            $this->guard('shipment_receipts', 'shipment_receipt_no_replacement', 'INSERT',
                'EXISTS (SELECT 1 FROM shipment_receipts WHERE id = NEW.id OR operation_key = NEW.operation_key)',
                'UNIQUE constraint failed: shipment_receipts.operation_key');
        }
    }

    private function protectReceiptItems(): void
    {
        $identity = $this->changed(['id', 'shipment_receipt_id', 'shipment_transit_item_id', 'shipment_stop_id',
            'transfer_intent_id', 'to_warehouse_id', 'product_id', 'received_quantity', 'note']);
        $invalid = 'NEW.received_quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.pending_putaway_quantity NOT BETWEEN 0 AND NEW.received_quantity';
        if ($this->sqlite()) {
            $invalid .= " OR typeof(NEW.received_quantity) <> 'integer' OR typeof(NEW.pending_putaway_quantity) <> 'integer'";
        }
        $this->guard('shipment_receipt_items', 'shipment_receipt_item_validate_insert', 'INSERT',
            "$invalid OR NEW.pending_putaway_quantity <> NEW.received_quantity",
            'Átvételkor a teljes átvett mennyiségnek betárolásra kell várnia.');
        $this->guard('shipment_receipt_items', 'shipment_receipt_item_require_open_header', 'INSERT',
            'EXISTS (SELECT 1 FROM shipment_receipts WHERE id = NEW.shipment_receipt_id AND result IS NOT NULL)',
            'A lezárt átvételhez nem adható új tétel.');
        $this->guard('shipment_receipt_items', 'shipment_receipt_item_protect_update', 'UPDATE',
            "$invalid OR $identity OR NEW.pending_putaway_quantity > OLD.pending_putaway_quantity",
            'Az átvételi tételen csak a betárolásra váró mennyiség csökkenthető.');
        $this->guard('shipment_receipt_items', 'shipment_receipt_item_no_delete', 'DELETE', '1 = 1',
            'A rögzített átvételi tétel nem törölhető.');
        if ($this->sqlite()) {
            $this->guard('shipment_receipt_items', 'shipment_receipt_item_no_replacement', 'INSERT',
                'EXISTS (SELECT 1 FROM shipment_receipt_items WHERE id = NEW.id OR
                    (shipment_receipt_id = NEW.shipment_receipt_id AND shipment_transit_item_id = NEW.shipment_transit_item_id))',
                'UNIQUE constraint failed: shipment_receipt_items.id');
        }
    }

    private function protectReceiptMovements(): void
    {
        $message = 'A fuvarátvétel készletmozgása nem írható felül és nem törölhető.';
        $this->guard('inventory_movements', 'movement_receipt_validate_insert', 'INSERT',
            "NEW.shipment_receipt_item_id IS NOT NULL AND
                (NEW.type <> 'in' OR NEW.shipment_transit_item_id IS NOT NULL OR NOT EXISTS (
                    SELECT 1 FROM shipment_receipt_items
                    WHERE id = NEW.shipment_receipt_item_id AND received_quantity = NEW.quantity
                ))",
            'A fuvarátvételhez az átvett mennyiséggel egyező bevétel tartozhat.');
        $this->guard('inventory_movements', 'movement_receipt_no_update', 'UPDATE',
            'OLD.shipment_receipt_item_id IS NOT NULL OR NEW.shipment_receipt_item_id IS NOT NULL', $message);
        $this->guard('inventory_movements', 'movement_receipt_no_delete', 'DELETE',
            'OLD.shipment_receipt_item_id IS NOT NULL', $message);
        if ($this->sqlite()) {
            $this->guard('inventory_movements', 'movement_receipt_no_replacement', 'INSERT',
                'EXISTS (SELECT 1 FROM inventory_movements WHERE shipment_receipt_item_id IS NOT NULL
                    AND (id = NEW.id OR shipment_receipt_item_id = NEW.shipment_receipt_item_id))',
                'UNIQUE constraint failed: inventory_movements.shipment_receipt_item_id');
        }
    }

    private function changed(array $columns): string
    {
        return implode(' OR ', array_map(fn (string $column) => $this->sqlite()
            ? "OLD.$column IS NOT NEW.$column"
            : "NOT (OLD.$column <=> NEW.$column)", $columns));
    }

    private function guard(string $table, string $name, string $event, string $when, string $message): void
    {
        DB::unprepared($this->sqlite()
            ? "CREATE TRIGGER $name BEFORE $event ON $table WHEN $when BEGIN SELECT RAISE(ABORT, '$message'); END"
            : "CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN
                IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF;
                END");
    }

    private function sqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }
};
