<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_load_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            // Historical IDs and labels survive bin renames and removal of fully used placements.
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('placement_id');
            $table->string('warehouse_name', 100);
            $table->string('location_code', 32);
            $table->string('location_name', 100);
            $table->string('product_name', 100);
            $table->string('sku', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('previous_weight_grams');
            $table->unsignedInteger('measured_weight_grams');
            $table->unsignedInteger('previous_pallet_slots');
            $table->unsignedInteger('measured_pallet_slots');
            $table->char('placement_revision', 64);
            $table->enum('result', ['verified', 'rejected']);
            $table->json('errors');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->dateTime('measured_at');
            $table->string('reason', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('operation_key')->unique();
            $table->char('request_hash', 64);
            $table->index(['warehouse_id', 'id']);
            $table->index(['warehouse_id', 'placement_id', 'id'], 'warehouse_load_verifications_placement_history');
        });

        $sqlite = DB::getDriverName() === 'sqlite';
        $current = $sqlite ? '' : ' LOCK IN SHARE MODE';
        $this->guard('INSERT', "NEW.quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.measured_weight_grams NOT BETWEEN 1 AND 1000000000
            OR NEW.measured_pallet_slots NOT BETWEEN 0 AND 1000000
            OR NEW.result NOT IN ('verified', 'rejected')
            OR NOT EXISTS (SELECT 1 FROM warehouse_placements AS placement
                JOIN stocks AS stock ON stock.id = placement.stock_id
                JOIN warehouse_locations AS location ON location.id = placement.warehouse_location_id
                WHERE placement.id = NEW.placement_id AND location.id = NEW.location_id
                    AND stock.warehouse_id = NEW.warehouse_id AND location.warehouse_id = NEW.warehouse_id
                    AND stock.product_id = NEW.product_id AND placement.quantity = NEW.quantity
                    AND ((NEW.result = 'verified' AND placement.load_requires_verification = 0
                        AND placement.cargo_weight_grams = NEW.measured_weight_grams AND placement.pallet_slots = NEW.measured_pallet_slots)
                        OR (NEW.result = 'rejected' AND placement.load_requires_verification = 1
                        AND placement.cargo_weight_grams = NEW.previous_weight_grams AND placement.pallet_slots = NEW.previous_pallet_slots))$current)");
        $this->guard('UPDATE', '1 = 1');
        $this->guard('DELETE', '1 = 1');
        if ($sqlite) {
            $this->guard('INSERT', 'EXISTS (SELECT 1 FROM warehouse_load_verifications WHERE id = NEW.id
                OR operation_key = NEW.operation_key)', 'replacement');
        }
    }

    public function down(): void
    {
        if (DB::table('warehouse_load_verifications')->exists()) {
            throw new RuntimeException('Rögzített terhelésellenőrzésekkel a migráció nem vonható vissza.');
        }

        Schema::dropIfExists('warehouse_load_verifications');
    }

    private function guard(string $event, string $condition, string $suffix = 'guard'): void
    {
        $name = 'warehouse_load_verifications_'.strtolower($event).'_'.$suffix;
        $message = 'Érvénytelen vagy utólag módosított terhelésellenőrzési naplóbejegyzés.';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON warehouse_load_verifications WHEN $condition BEGIN SELECT RAISE(ABORT, '$message'); END"
            : "CREATE TRIGGER $name BEFORE $event ON warehouse_load_verifications FOR EACH ROW BEGIN
                IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF; END");
    }
};
