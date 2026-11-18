<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_transfer_tasks', fn (Blueprint $table) => $table->dateTime('dispatched_at')->nullable());

        Schema::table('shipment_intent_assignments', fn (Blueprint $table) => $table->unique(
            ['id', 'shipment_id', 'transfer_intent_id', 'shipment_stop_id'], 'shipment_assignment_transit_identity_unique',
        ));
        Schema::table('shipment_stops', fn (Blueprint $table) => $table->unique(
            ['id', 'warehouse_id'], 'shipment_stop_transit_destination_unique',
        ));

        Schema::create('shipment_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->dateTime('dispatched_at');
            $table->string('note', 500)->nullable();
            $table->json('snapshot');
            $table->unique(['id', 'shipment_id'], 'shipment_dispatch_transit_identity_unique');
        });

        Schema::create('shipment_transit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id');
            $table->foreignId('shipment_dispatch_id');
            $table->foreignId('transfer_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('shipment_intent_assignment_id')->unique();
            $table->foreignId('warehouse_transfer_task_id')->unique()
                ->constrained('warehouse_transfer_tasks', indexName: 'shipment_transit_task_fk')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->foreignId('shipment_stop_id');
            $table->unsignedInteger('dispatched_quantity');
            $table->unsignedInteger('remaining_quantity');
            $table->enum('status', ['in_transit', 'received'])->default('in_transit');
            $table->timestamps();

            $table->foreign(['shipment_dispatch_id', 'shipment_id'], 'shipment_transit_dispatch_fk')
                ->references(['id', 'shipment_id'])->on('shipment_dispatches')->restrictOnDelete();
            $table->foreign(['shipment_intent_assignment_id', 'shipment_id', 'transfer_intent_id', 'shipment_stop_id'], 'shipment_transit_assignment_fk')
                ->references(['id', 'shipment_id', 'transfer_intent_id', 'shipment_stop_id'])->on('shipment_intent_assignments')->restrictOnDelete();
            $table->foreign(['shipment_stop_id', 'to_warehouse_id'], 'shipment_transit_stop_destination_fk')
                ->references(['id', 'warehouse_id'])->on('shipment_stops')->restrictOnDelete();
            $table->index(['shipment_id', 'shipment_stop_id'], 'shipment_transit_shipment_stop_index');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('shipment_transit_item_id')->nullable()->unique('movement_shipment_transit_item_unique')
                ->constrained('shipment_transit_items')->restrictOnDelete();
        });

        $this->protectDispatchHistory();
        $this->validateTransitItems();
        if (DB::getDriverName() === 'sqlite') {
            $this->preventSqliteReplacement('shipment_dispatches', ['id', 'shipment_id']);
            $this->preventSqliteReplacement('shipment_transit_items', ['id', 'transfer_intent_id', 'shipment_intent_assignment_id', 'warehouse_transfer_task_id']);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['shipment_transit_item_id']);
            $table->dropUnique('movement_shipment_transit_item_unique');
            $table->dropColumn('shipment_transit_item_id');
        });
        Schema::dropIfExists('shipment_transit_items');
        Schema::dropIfExists('shipment_dispatches');
        Schema::table('shipment_stops', fn (Blueprint $table) => $table->dropUnique('shipment_stop_transit_destination_unique'));
        Schema::table('shipment_intent_assignments', fn (Blueprint $table) => $table->dropUnique('shipment_assignment_transit_identity_unique'));
        Schema::table('warehouse_transfer_tasks', fn (Blueprint $table) => $table->dropColumn('dispatched_at'));
    }

    private function protectDispatchHistory(): void
    {
        $message = 'A fuvar feladási pillanatképe nem módosítható és nem törölhető.';
        foreach (['UPDATE', 'DELETE'] as $event) {
            $trigger = 'shipment_dispatches_no_'.strtolower($event);
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER $trigger BEFORE $event ON shipment_dispatches BEGIN SELECT RAISE(ABORT, '$message'); END"
                : "CREATE TRIGGER $trigger BEFORE $event ON shipment_dispatches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'");
        }
    }

    private function validateTransitItems(): void
    {
        $identity = ['id', 'shipment_id', 'shipment_dispatch_id', 'transfer_intent_id', 'shipment_intent_assignment_id',
            'warehouse_transfer_task_id', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'shipment_stop_id', 'dispatched_quantity'];
        $changedIdentity = implode(' OR ', array_map(fn (string $column) => "OLD.$column <> NEW.$column", $identity));
        $message = 'A tranzittétel feladáskori adatai nem módosíthatók.';

        if (DB::getDriverName() !== 'sqlite') {
            $quantityCheck = $this->transitQuantityCheck();
            DB::statement("ALTER TABLE shipment_transit_items ADD CONSTRAINT shipment_transit_quantity_check CHECK ($quantityCheck)");
            DB::unprepared(<<<SQL
                CREATE TRIGGER shipment_transit_items_protect_identity BEFORE UPDATE ON shipment_transit_items
                FOR EACH ROW BEGIN
                    IF $changedIdentity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; END IF;
                END
                SQL);

            return;
        }

        $quantityCheck = $this->transitQuantityCheck('NEW.');
        foreach (['INSERT', 'UPDATE'] as $event) {
            $trigger = 'shipment_transit_items_validate_'.strtolower($event);
            $immutableCheck = $event === 'UPDATE' ? "SELECT CASE WHEN $changedIdentity THEN RAISE(ABORT, '$message') END;" : '';
            DB::unprepared(<<<SQL
                CREATE TRIGGER $trigger BEFORE $event ON shipment_transit_items
                BEGIN
                    SELECT CASE WHEN typeof(NEW.dispatched_quantity) <> 'integer'
                        OR typeof(NEW.remaining_quantity) <> 'integer' OR NOT ($quantityCheck)
                        THEN RAISE(ABORT, 'A tranzittétel állapota és mennyisége nem egyezik.') END;
                    $immutableCheck
                END
                SQL);
        }
    }

    private function transitQuantityCheck(string $prefix = ''): string
    {
        return "{$prefix}dispatched_quantity BETWEEN 1 AND 2147483647
            AND {$prefix}remaining_quantity BETWEEN 0 AND {$prefix}dispatched_quantity
            AND (({$prefix}status = 'in_transit' AND {$prefix}remaining_quantity > 0)
                OR ({$prefix}status = 'received' AND {$prefix}remaining_quantity = 0))";
    }

    private function preventSqliteReplacement(string $table, array $uniqueColumns): void
    {
        // SQLite REPLACE may skip a DELETE trigger, even for an immutable dispatch record.
        $conditions = implode(' OR ', array_map(fn (string $column) => "$column = NEW.$column", $uniqueColumns));
        DB::unprepared("CREATE TRIGGER {$table}_no_replacement BEFORE INSERT ON $table BEGIN
            SELECT CASE WHEN EXISTS (SELECT 1 FROM $table WHERE $conditions)
                THEN RAISE(ABORT, 'UNIQUE constraint failed: $table.id') END;
            END");
    }
};
