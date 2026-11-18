<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->enum('status', ['active', 'released', 'consumed']);
            $table->foreignId('reserved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reserved_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('active_intent_id')
                ->virtualAs("CASE WHEN status = 'active' THEN transfer_intent_id ELSE NULL END");

            $table->unique('active_intent_id');
            $table->index(['stock_id', 'status']);
            $table->index(['transfer_intent_id', 'id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $trigger = 'transfer_stock_reservations_validate_'.strtolower($operation);
                DB::statement(<<<SQL
                    CREATE TRIGGER $trigger
                    BEFORE $operation ON transfer_stock_reservations
                    BEGIN
                        SELECT CASE WHEN typeof(NEW.quantity) <> 'integer' OR NEW.quantity NOT BETWEEN 1 AND 2147483647
                            OR typeof(NEW.remaining_quantity) <> 'integer' OR NEW.remaining_quantity NOT BETWEEN 0 AND NEW.quantity
                            THEN RAISE(ABORT, 'A foglalás mennyisége vagy a még foglalt mennyiség érvénytelen.') END;
                        SELECT CASE WHEN NOT (
                            (NEW.status = 'active' AND NEW.remaining_quantity > 0 AND NEW.released_by IS NULL AND NEW.released_at IS NULL)
                            OR (NEW.status = 'released' AND NEW.remaining_quantity = 0 AND NEW.released_by IS NOT NULL AND NEW.released_at IS NOT NULL)
                            OR (NEW.status = 'consumed' AND NEW.remaining_quantity = 0 AND NEW.released_by IS NULL AND NEW.released_at IS NULL)
                        ) THEN RAISE(ABORT, 'A foglalás állapota és adatai nem egyeznek.') END;
                    END
                    SQL);
            }

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE transfer_stock_reservations
                ADD CONSTRAINT transfer_reservations_quantity_range
                    CHECK (quantity BETWEEN 1 AND 2147483647 AND remaining_quantity BETWEEN 0 AND quantity),
                ADD CONSTRAINT transfer_reservations_lifecycle
                    CHECK (
                        (status = 'active' AND remaining_quantity > 0 AND released_by IS NULL AND released_at IS NULL)
                        OR (status = 'released' AND remaining_quantity = 0 AND released_by IS NOT NULL AND released_at IS NOT NULL)
                        OR (status = 'consumed' AND remaining_quantity = 0 AND released_by IS NULL AND released_at IS NULL)
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_stock_reservations');
    }
};
