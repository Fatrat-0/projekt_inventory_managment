<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 29)->unique();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_arrival_at');
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->enum('priority', ['normal', 'urgent', 'critical'])->default('normal');
            $table->enum('shipping_preference', ['groupable', 'dedicated_requested'])->default('groupable');
            $table->enum('status', ['pending_approval', 'approved', 'rejected', 'cancelled'])->default('pending_approval');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
            $table->index('product_id');
            $table->index('requested_by');
        });

        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a CHECK to an existing table and its integer affinity accepts real values.
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $trigger = 'transfer_intents_validate_'.strtolower($operation);
                DB::statement(<<<SQL
                    CREATE TRIGGER $trigger
                    BEFORE $operation ON transfer_intents
                    BEGIN
                        SELECT CASE WHEN typeof(NEW.quantity) <> 'integer' OR NEW.quantity NOT BETWEEN 1 AND 2147483647
                            THEN RAISE(ABORT, 'A kért mennyiség 1 és 2 147 483 647 közötti egész szám legyen.') END;
                        SELECT CASE WHEN NEW.from_warehouse_id = NEW.to_warehouse_id
                            THEN RAISE(ABORT, 'A forrásraktár és a célraktár nem lehet ugyanaz.') END;
                        SELECT CASE WHEN typeof(NEW.version) <> 'integer' OR NEW.version NOT BETWEEN 1 AND 4294967295
                            THEN RAISE(ABORT, 'Az igény verziója pozitív egész szám legyen.') END;
                    END
                    SQL);
            }

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE transfer_intents
                ADD CONSTRAINT transfer_intents_quantity_range CHECK (quantity BETWEEN 1 AND 2147483647),
                ADD CONSTRAINT transfer_intents_distinct_warehouses CHECK (from_warehouse_id <> to_warehouse_id),
                ADD CONSTRAINT transfer_intents_positive_version CHECK (version > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_intents');
    }
};
