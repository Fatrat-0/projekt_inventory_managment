<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_receipt_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('pending_putaway_quantity');
            $table->unsignedInteger('putaway_version')->default(1);
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'product_id', 'pending_putaway_quantity'], 'manual_receipt_pending_index');
        });

        Schema::create('manual_receipt_putaways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_receipt_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_placement_id')->unique();
            $table->unsignedBigInteger('warehouse_location_id');
            $table->string('location_code', 32);
            $table->string('location_name', 100);
            $table->string('product_name', 100);
            $table->string('sku', 100);
            $table->string('warehouse_name', 100);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('cargo_weight_grams');
            $table->unsignedInteger('pallet_slots');
            $table->json('dimensions_cm')->nullable();
            $table->enum('geometry_status', ['verified', 'site_verified']);
            $table->boolean('site_verified');
            $table->enum('weight_source', ['measured', 'calculated']);
            $table->char('packaging_revision', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('operation_key')->unique();
            $table->char('request_hash', 64);
            $table->json('result');
            $table->dateTime('placed_at');
            $table->index(['manual_receipt_batch_id', 'id'], 'manual_putaway_history_index');
        });

        $invalidBalance = 'NEW.received_quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.pending_putaway_quantity NOT BETWEEN 0 AND NEW.received_quantity
            OR NEW.putaway_version < 1
            OR (NEW.pending_putaway_quantity = 0 AND NEW.closed_at IS NULL)
            OR (NEW.pending_putaway_quantity > 0 AND NEW.closed_at IS NOT NULL)';
        $sqlite = DB::getDriverName() === 'sqlite';
        $current = $sqlite ? '' : ' LOCK IN SHARE MODE';
        $this->guard('manual_receipt_batches', 'INSERT', "$invalidBalance
            OR NEW.pending_putaway_quantity <> NEW.received_quantity OR NEW.putaway_version <> 1
            OR NOT EXISTS (SELECT 1 FROM inventory_movements AS movement
                WHERE movement.id = NEW.movement_id AND movement.type = 'in' AND movement.return_of_id IS NULL
                    AND movement.shipment_receipt_item_id IS NULL AND movement.product_id = NEW.product_id
                    AND movement.warehouse_id = NEW.warehouse_id AND movement.quantity = NEW.received_quantity$current)");
        $this->guard('manual_receipt_batches', 'UPDATE', "$invalidBalance
            OR NEW.movement_id <> OLD.movement_id OR NEW.product_id <> OLD.product_id
            OR NEW.warehouse_id <> OLD.warehouse_id OR NEW.received_quantity <> OLD.received_quantity
            OR NEW.pending_putaway_quantity >= OLD.pending_putaway_quantity
            OR NEW.putaway_version <> OLD.putaway_version + 1");
        $this->guard('manual_receipt_batches', 'DELETE', '1 = 1');
        $this->guard('manual_receipt_putaways', 'INSERT', "NEW.quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.cargo_weight_grams NOT BETWEEN 1 AND 1000000000 OR NEW.pallet_slots NOT BETWEEN 0 AND 1000000
            OR (NEW.geometry_status = 'site_verified' AND NEW.site_verified <> 1)
            OR (NEW.weight_source = 'calculated' AND NEW.packaging_revision IS NULL)
            OR (NEW.weight_source = 'measured' AND NEW.packaging_revision IS NOT NULL)
            OR NOT EXISTS (SELECT 1 FROM warehouse_placements AS placement
                JOIN stocks AS stock ON stock.id = placement.stock_id
                JOIN manual_receipt_batches AS batch ON batch.id = NEW.manual_receipt_batch_id
                WHERE placement.id = NEW.warehouse_placement_id AND placement.warehouse_location_id = NEW.warehouse_location_id
                    AND placement.quantity = NEW.quantity AND placement.cargo_weight_grams = NEW.cargo_weight_grams
                    AND placement.pallet_slots = NEW.pallet_slots AND stock.product_id = batch.product_id
                    AND stock.warehouse_id = batch.warehouse_id$current)");
        $this->guard('manual_receipt_putaways', 'UPDATE', '1 = 1');
        $this->guard('manual_receipt_putaways', 'DELETE', '1 = 1');
        if ($sqlite) {
            // SQLite's REPLACE can bypass BEFORE DELETE triggers by default.
            $this->guard('manual_receipt_putaways', 'INSERT', 'EXISTS (SELECT 1 FROM manual_receipt_putaways
                WHERE id = NEW.id OR operation_key = NEW.operation_key OR warehouse_placement_id = NEW.warehouse_placement_id)', 'replacement');
            $this->guard('manual_receipt_batches', 'INSERT', 'EXISTS (SELECT 1 FROM manual_receipt_batches
                WHERE id = NEW.id OR movement_id = NEW.movement_id)', 'replacement');
        }
    }

    public function down(): void
    {
        if (DB::table('manual_receipt_batches')->exists()) {
            throw new RuntimeException('Rögzített manuális bevételezéssel a migráció nem vonható vissza.');
        }

        Schema::dropIfExists('manual_receipt_putaways');
        Schema::dropIfExists('manual_receipt_batches');
    }

    private function guard(string $table, string $event, string $when, string $suffix = 'guard'): void
    {
        $name = $table.'_'.strtolower($event).'_'.$suffix;
        $message = 'Érvénytelen manuális bevételezési vagy betárolási adat.';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON $table WHEN $when BEGIN SELECT RAISE(ABORT, '$message'); END"
            : "CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN
                IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF; END");
    }
};
