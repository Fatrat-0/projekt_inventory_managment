<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_relocations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->string('warehouse_name', 100);
            $table->string('product_name', 100);
            $table->string('sku', 100);
            // These are historical identifiers. Bin or placement removal must not erase the event.
            $table->unsignedBigInteger('from_location_id');
            $table->string('from_location_code', 32);
            $table->string('from_location_name', 100);
            $table->unsignedBigInteger('to_location_id');
            $table->string('to_location_code', 32);
            $table->string('to_location_name', 100);
            $table->unsignedBigInteger('destination_placement_id')->unique();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('cargo_weight_grams');
            $table->unsignedInteger('pallet_slots');
            $table->json('dimensions_cm')->nullable();
            $table->enum('load_source', ['recorded', 'measured']);
            $table->boolean('source_load_verified');
            $table->boolean('site_verified');
            $table->enum('geometry_status', ['verified', 'site_verified']);
            $table->json('source_allocations');
            $table->json('balances');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->dateTime('relocated_at');
            $table->string('reason', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('operation_key')->unique();
            $table->char('request_hash', 64);
            $table->index(['warehouse_id', 'id']);
        });

        $sqlite = DB::getDriverName() === 'sqlite';
        $current = $sqlite ? '' : ' LOCK IN SHARE MODE';
        $this->guard('INSERT', "NEW.from_location_id = NEW.to_location_id
            OR NEW.quantity NOT BETWEEN 1 AND 2147483647
            OR NEW.cargo_weight_grams NOT BETWEEN 1 AND 1000000000
            OR NEW.pallet_slots NOT BETWEEN 0 AND 1000000
            OR (NEW.geometry_status = 'site_verified' AND NEW.site_verified <> 1)
            OR NOT EXISTS (SELECT 1 FROM warehouse_placements AS placement
                JOIN stocks AS stock ON stock.id = placement.stock_id
                JOIN warehouse_locations AS target ON target.id = placement.warehouse_location_id AND target.warehouse_id = stock.warehouse_id
                JOIN warehouse_locations AS source ON source.id = NEW.from_location_id AND source.warehouse_id = stock.warehouse_id
                WHERE placement.id = NEW.destination_placement_id AND target.id = NEW.to_location_id
                    AND stock.warehouse_id = NEW.warehouse_id AND stock.product_id = NEW.product_id
                    AND placement.quantity = NEW.quantity AND placement.cargo_weight_grams = NEW.cargo_weight_grams
                    AND placement.pallet_slots = NEW.pallet_slots$current)");
        $this->guard('UPDATE', '1 = 1');
        $this->guard('DELETE', '1 = 1');
        if ($sqlite) {
            // SQLite REPLACE can otherwise replace an event without invoking its delete trigger.
            $this->guard('INSERT', 'EXISTS (SELECT 1 FROM warehouse_relocations WHERE id = NEW.id
                OR reference = NEW.reference OR operation_key = NEW.operation_key
                OR destination_placement_id = NEW.destination_placement_id)', 'replacement');
        }
    }

    public function down(): void
    {
        if (DB::table('warehouse_relocations')->exists()) {
            throw new RuntimeException('Rögzített áthelyezésekkel a migráció nem vonható vissza.');
        }

        Schema::dropIfExists('warehouse_relocations');
    }

    private function guard(string $event, string $condition, string $suffix = 'guard'): void
    {
        $name = 'warehouse_relocations_'.strtolower($event).'_'.$suffix;
        $message = 'Érvénytelen vagy utólag módosított áthelyezési naplóbejegyzés.';
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER $name BEFORE $event ON warehouse_relocations WHEN $condition BEGIN SELECT RAISE(ABORT, '$message'); END"
            : "CREATE TRIGGER $name BEFORE $event ON warehouse_relocations FOR EACH ROW BEGIN
                IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF; END");
    }
};
