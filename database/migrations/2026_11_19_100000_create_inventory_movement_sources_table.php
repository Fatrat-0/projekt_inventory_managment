<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->enum('source_type', ['location', 'unassigned']);
            // Historical identifiers and display values survive changes to the source bin.
            $table->unsignedBigInteger('warehouse_location_id')->nullable();
            $table->string('location_code', 32)->nullable();
            $table->string('location_name', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->index(['movement_id', 'id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            $rule = $this->validSource('NEW.');
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $trigger = 'inventory_movement_sources_validate_'.strtolower($operation);
                DB::statement("CREATE TRIGGER $trigger BEFORE $operation ON inventory_movement_sources
                    WHEN typeof(NEW.quantity) <> 'integer' OR NOT ($rule)
                    BEGIN SELECT RAISE(ABORT, 'Érvénytelen készletkiadási forrásadat.'); END");
            }

            return;
        }

        DB::statement('ALTER TABLE inventory_movement_sources ADD CONSTRAINT movement_sources_valid CHECK ('.$this->validSource().')');
    }

    private function validSource(string $prefix = ''): string
    {
        return "{$prefix}quantity BETWEEN 1 AND 2147483647 AND (
            ({$prefix}source_type = 'location' AND {$prefix}warehouse_location_id IS NOT NULL AND {$prefix}warehouse_location_id > 0
                AND {$prefix}location_code IS NOT NULL AND {$prefix}location_name IS NOT NULL)
            OR ({$prefix}source_type = 'unassigned' AND {$prefix}warehouse_location_id IS NULL
                AND {$prefix}location_code IS NULL AND {$prefix}location_name IS NULL))";
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_sources');
    }
};
