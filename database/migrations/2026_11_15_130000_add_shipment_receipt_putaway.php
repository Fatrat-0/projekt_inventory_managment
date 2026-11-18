<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_receipt_items', fn (Blueprint $table) => $table->unsignedInteger('putaway_version')->default(1));

        Schema::create('shipment_receipt_putaways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_receipt_item_id')->constrained()->restrictOnDelete();
            // The original identifiers survive normal picking, release and empty-location deletion.
            $table->unsignedBigInteger('warehouse_placement_id')->unique();
            $table->unsignedBigInteger('warehouse_location_id');
            $table->string('location_code', 32);
            $table->string('location_name', 100);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
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
            $table->index(['shipment_receipt_item_id', 'id'], 'shipment_putaway_item_history_index');
        });

        $this->protectPutaways();
    }

    public function down(): void
    {
        if (DB::table('shipment_receipt_putaways')->exists()) {
            throw new RuntimeException('Rögzített betárolásokkal a migráció nem vonható vissza.');
        }

        Schema::dropIfExists('shipment_receipt_putaways');
        Schema::table('shipment_receipt_items', fn (Blueprint $table) => $table->dropColumn('putaway_version'));
    }

    private function protectPutaways(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        // A current read also sees a receipt/stock committed after an InnoDB outer snapshot.
        $current = $sqlite ? '' : ' LOCK IN SHARE MODE';
        $invalid = "NEW.quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.cargo_weight_grams NOT BETWEEN 1 AND 1000000000
            OR NEW.pallet_slots NOT BETWEEN 0 AND 1000000
            OR (NEW.geometry_status = 'site_verified' AND NEW.site_verified <> 1)
            OR (NEW.weight_source = 'calculated' AND NEW.packaging_revision IS NULL)
            OR (NEW.weight_source = 'measured' AND NEW.packaging_revision IS NOT NULL)
            OR NOT EXISTS (
                SELECT 1 FROM warehouse_placements AS placement
                JOIN stocks AS stock ON stock.id = placement.stock_id
                JOIN shipment_receipt_items AS item ON item.id = NEW.shipment_receipt_item_id
                JOIN shipment_receipts AS receipt ON receipt.id = item.shipment_receipt_id
                WHERE placement.id = NEW.warehouse_placement_id
                    AND placement.warehouse_location_id = NEW.warehouse_location_id
                    AND placement.quantity = NEW.quantity
                    AND placement.cargo_weight_grams = NEW.cargo_weight_grams
                    AND placement.pallet_slots = NEW.pallet_slots
                    AND stock.product_id = item.product_id AND stock.warehouse_id = item.to_warehouse_id
                    AND receipt.result IS NOT NULL{$current}
            )";
        if ($sqlite) {
            $invalid .= " OR typeof(NEW.quantity) <> 'integer' OR typeof(NEW.cargo_weight_grams) <> 'integer'
                OR typeof(NEW.pallet_slots) <> 'integer' OR NEW.site_verified NOT IN (0, 1)";
        }
        $this->guard('shipment_putaway_validate_insert', 'INSERT', $invalid,
            'A betárolási naplóhoz az átvételi tétellel egyező fizikai elhelyezés szükséges.');
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('shipment_putaway_no_'.strtolower($event), $event, '1 = 1',
                'A rögzített betárolás nem módosítható és nem törölhető.');
        }
        if ($sqlite) {
            $this->guard('shipment_putaway_no_replacement', 'INSERT',
                'EXISTS (SELECT 1 FROM shipment_receipt_putaways WHERE id = NEW.id
                    OR operation_key = NEW.operation_key OR warehouse_placement_id = NEW.warehouse_placement_id)',
                'UNIQUE constraint failed: shipment_receipt_putaways.operation_key');
        }
    }

    private function guard(string $name, string $event, string $when, string $message): void
    {
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON shipment_receipt_putaways
                WHEN $when BEGIN SELECT RAISE(ABORT, '$message'); END"
            : "CREATE TRIGGER $name BEFORE $event ON shipment_receipt_putaways FOR EACH ROW BEGIN
                IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF;
                END");
    }
};
