<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfer_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('transfer_stock_reservation_id')
                ->constrained('transfer_stock_reservations', 'id', 'warehouse_task_reservation_fk')->restrictOnDelete();
            $table->foreignId('stock_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->enum('type', ['picking'])->default('picking');
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('staged_quantity')->default(0);
            $table->enum('status', ['pending', 'in_progress', 'picked', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_intent_id')
                ->virtualAs("CASE WHEN status IN ('pending', 'in_progress', 'picked') THEN transfer_intent_id ELSE NULL END");

            $table->unique('active_intent_id');
            $table->index(['stock_id', 'status']);
            $table->index(['transfer_intent_id', 'id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            $quantity = $this->quantityCheck('NEW.');
            $lifecycle = $this->lifecycleCheck('NEW.');
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $trigger = 'warehouse_transfer_tasks_validate_'.strtolower($operation);
                DB::statement(<<<SQL
                    CREATE TRIGGER $trigger
                    BEFORE $operation ON warehouse_transfer_tasks
                    BEGIN
                        SELECT CASE WHEN typeof(NEW.expected_quantity) <> 'integer' OR typeof(NEW.staged_quantity) <> 'integer'
                            OR NOT ($quantity) THEN RAISE(ABORT, 'A kitárolási feladat mennyisége érvénytelen.') END;
                        SELECT CASE WHEN NOT ($lifecycle) THEN RAISE(ABORT, 'A kitárolási feladat állapota és adatai nem egyeznek.') END;
                    END
                    SQL);
            }

            return;
        }

        $quantity = $this->quantityCheck();
        $lifecycle = $this->lifecycleCheck();
        DB::statement(<<<SQL
            ALTER TABLE warehouse_transfer_tasks
                ADD CONSTRAINT warehouse_tasks_quantity_range CHECK ($quantity),
                ADD CONSTRAINT warehouse_tasks_lifecycle CHECK ($lifecycle)
            SQL);
    }

    private function quantityCheck(string $prefix = ''): string
    {
        return "{$prefix}expected_quantity BETWEEN 1 AND 2147483647
            AND {$prefix}staged_quantity BETWEEN 0 AND {$prefix}expected_quantity";
    }

    private function lifecycleCheck(string $prefix = ''): string
    {
        return <<<SQL
            ({$prefix}status IN ('pending', 'cancelled') AND {$prefix}staged_quantity = 0
                AND {$prefix}performed_by IS NULL AND {$prefix}started_at IS NULL AND {$prefix}completed_at IS NULL)
            OR ({$prefix}status = 'in_progress' AND {$prefix}staged_quantity > 0 AND {$prefix}staged_quantity < {$prefix}expected_quantity
                AND {$prefix}performed_by IS NOT NULL AND {$prefix}started_at IS NOT NULL AND {$prefix}completed_at IS NULL)
            OR ({$prefix}status = 'picked' AND {$prefix}staged_quantity = {$prefix}expected_quantity
                AND {$prefix}performed_by IS NOT NULL AND {$prefix}started_at IS NOT NULL
                AND {$prefix}completed_at IS NOT NULL AND {$prefix}completed_at >= {$prefix}started_at)
            SQL;
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_tasks');
    }
};
