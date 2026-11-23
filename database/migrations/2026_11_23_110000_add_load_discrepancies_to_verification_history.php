<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_load_verifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('capacity_violation_mask')->nullable();
            $table->unsignedBigInteger('resolves_verification_id')->nullable()->unique();
            $table->index(['location_id', 'result', 'id'], 'warehouse_load_verifications_location_result');
        });

        DB::unprepared('DROP TRIGGER warehouse_load_verifications_insert_guard');
        $sqlite = DB::getDriverName() === 'sqlite';
        $current = $sqlite ? '' : ' LOCK IN SHARE MODE';
        $this->guard('INSERT', "(NEW.capacity_violation_mask IS NOT NULL AND (NEW.capacity_violation_mask > 31
                OR (NEW.result = 'verified' AND NEW.capacity_violation_mask <> 0)))
            OR (NEW.result = 'rejected' AND NEW.resolves_verification_id IS NOT NULL)
            OR (NEW.quantity = 0 AND (NEW.result <> 'verified' OR NEW.resolves_verification_id IS NULL
                OR NEW.previous_weight_grams <> 0 OR NEW.measured_weight_grams <> 0
                OR NEW.previous_pallet_slots <> 0 OR NEW.measured_pallet_slots <> 0
                OR EXISTS (SELECT 1 FROM warehouse_placements WHERE id = NEW.placement_id$current)))
            OR (NEW.quantity <> 0 AND (NEW.quantity NOT BETWEEN 1 AND 2147483647
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
                        AND placement.cargo_weight_grams = NEW.previous_weight_grams AND placement.pallet_slots = NEW.previous_pallet_slots))$current)))");
    }

    public function down(): void
    {
        if (DB::table('warehouse_load_verifications')->whereNotNull('capacity_violation_mask')->exists()) {
            throw new RuntimeException('A terhelési eltérések megtartásához a migráció nem vonható vissza.');
        }

        DB::unprepared('DROP TRIGGER warehouse_load_verifications_insert_guard');
        Schema::table('warehouse_load_verifications', function (Blueprint $table) {
            $table->dropUnique(['resolves_verification_id']);
            $table->dropIndex('warehouse_load_verifications_location_result');
            $table->dropColumn(['capacity_violation_mask', 'resolves_verification_id']);
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
