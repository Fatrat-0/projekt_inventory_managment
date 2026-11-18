<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->enum('planning_mode', ['legacy', 'transfer_intent'])->default('legacy');
            $table->dateTime('planned_departure_at')->nullable();
            $table->index(['planning_mode', 'origin_warehouse_id'], 'shipments_planning_origin_index');
        });

        Schema::table('shipment_stops', fn (Blueprint $table) =>
            $table->unique(['shipment_id', 'id'], 'shipment_stops_shipment_id_unique'));

        Schema::create('shipment_intent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('transfer_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_stop_id');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->foreignId('detached_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('detached_at')->nullable();
            $table->string('detach_reason', 500)->nullable();
            $table->unsignedBigInteger('active_intent_id')
                ->virtualAs('CASE WHEN detached_at IS NULL THEN transfer_intent_id ELSE NULL END');
            $table->unique('active_intent_id');
            $table->unique(['shipment_id', 'transfer_intent_id'], 'shipment_intent_once_per_plan');
            $table->foreign(['shipment_id', 'shipment_stop_id'], 'shipment_intent_stop_fk')
                ->references(['shipment_id', 'id'])->on('shipment_stops')->restrictOnDelete();
        });

        Schema::create('shipment_plan_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('action', 24);
            $table->char('request_hash', 64);
            $table->string('reason', 500)->nullable();
            $table->json('result');
            $table->dateTime('occurred_at');
        });

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'shipment_plan_operations_no_'.strtolower($event);
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE {$event} ON shipment_plan_operations BEGIN SELECT RAISE(ABORT, 'A fuvarterv naplója csak hozzáfűzhető.'); END"
                : "CREATE TRIGGER {$name} BEFORE {$event} ON shipment_plan_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A fuvarterv naplója csak hozzáfűzhető.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_plan_operations');
        Schema::dropIfExists('shipment_intent_assignments');
        Schema::table('shipment_stops', fn (Blueprint $table) => $table->dropUnique('shipment_stops_shipment_id_unique'));
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_planning_origin_index');
            $table->dropColumn(['planning_mode', 'planned_departure_at']);
        });
    }
};
