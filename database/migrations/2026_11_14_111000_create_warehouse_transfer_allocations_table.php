<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfer_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_task_id')
                ->constrained('warehouse_transfer_tasks', 'id', 'warehouse_allocations_task_fk')->restrictOnDelete();
            $table->foreignId('transfer_workflow_event_id')
                ->constrained('transfer_workflow_events', 'id', 'warehouse_allocations_event_fk')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()
                ->constrained('warehouse_locations', 'id', 'warehouse_allocations_location_fk')->restrictOnDelete();
            $table->unsignedBigInteger('source_placement_id')->nullable();
            $table->string('location_code', 32)->nullable();
            $table->string('location_name', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->enum('source_type', ['location', 'unassigned']);
            $table->unsignedInteger('cargo_weight_grams')->nullable();
            $table->unsignedInteger('pallet_slots')->nullable();
            $table->foreignId('performed_by')->constrained('users', 'id', 'warehouse_allocations_actor_fk')->restrictOnDelete();
            $table->timestamp('performed_at');

            $table->index(['warehouse_transfer_task_id', 'id'], 'warehouse_allocations_task_history_idx');
        });

        if (DB::getDriverName() === 'sqlite') {
            $source = $this->sourceCheck('NEW.');
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $trigger = 'warehouse_transfer_allocations_validate_'.strtolower($operation);
                DB::statement(<<<SQL
                    CREATE TRIGGER $trigger
                    BEFORE $operation ON warehouse_transfer_allocations
                    BEGIN
                        SELECT CASE WHEN typeof(NEW.quantity) <> 'integer' OR NEW.quantity NOT BETWEEN 1 AND 2147483647
                            OR (NEW.cargo_weight_grams IS NOT NULL AND (typeof(NEW.cargo_weight_grams) <> 'integer' OR NEW.cargo_weight_grams < 0))
                            OR (NEW.pallet_slots IS NOT NULL AND (typeof(NEW.pallet_slots) <> 'integer' OR NEW.pallet_slots < 0))
                            THEN RAISE(ABORT, 'A kitárolási allokáció mennyiségi adatai érvénytelenek.') END;
                        SELECT CASE WHEN NOT ($source) THEN RAISE(ABORT, 'A kitárolási allokáció forrásadatai hiányosak.') END;
                    END
                    SQL);
            }

            return;
        }

        $source = $this->sourceCheck();
        DB::statement(<<<SQL
            ALTER TABLE warehouse_transfer_allocations
                ADD CONSTRAINT warehouse_allocations_quantity_range CHECK (quantity BETWEEN 1 AND 2147483647),
                ADD CONSTRAINT warehouse_allocations_source_shape CHECK ($source)
            SQL);
    }

    private function sourceCheck(string $prefix = ''): string
    {
        return <<<SQL
            ({$prefix}source_type = 'location' AND {$prefix}warehouse_location_id IS NOT NULL
                AND {$prefix}source_placement_id IS NOT NULL AND {$prefix}source_placement_id > 0
                AND {$prefix}location_code IS NOT NULL AND {$prefix}location_name IS NOT NULL)
            OR ({$prefix}source_type = 'unassigned' AND {$prefix}warehouse_location_id IS NULL
                AND {$prefix}source_placement_id IS NULL AND {$prefix}location_code IS NULL AND {$prefix}location_name IS NULL)
            SQL;
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_allocations');
    }
};
