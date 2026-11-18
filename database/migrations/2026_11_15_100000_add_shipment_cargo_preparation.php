<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packagings', fn (Blueprint $table) => $table->unsignedBigInteger('revision')->default(0));

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('registration_number', 32)->nullable();
            $table->unsignedBigInteger('payload_capacity_grams')->nullable();
            $table->unsignedInteger('pallet_slots_capacity')->nullable();
            $table->boolean('active')->default(true);
            $table->string('note', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('vehicle_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('action', 24);
            $table->char('request_hash', 64);
            $table->json('result');
            $table->dateTime('occurred_at');
        });

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'vehicle_operations_no_'.strtolower($event);
            DB::unprepared(DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE {$event} ON vehicle_operations BEGIN SELECT RAISE(ABORT, 'A járműnapló csak hozzáfűzhető.'); END"
                : "CREATE TRIGGER {$name} BEFORE {$event} ON vehicle_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A járműnapló csak hozzáfűzhető.'");
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('cargo_revision')->default(0);
        });

        Schema::create('shipment_assignment_cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_intent_assignment_id')->unique()
                ->constrained('shipment_intent_assignments', indexName: 'shipment_assignment_cargo_assignment_fk')->restrictOnDelete();
            $table->unsignedBigInteger('gross_weight_grams')->nullable();
            $table->enum('source', ['manual', 'calculated']);
            $table->json('dimensions_cm')->nullable();
            $table->string('packaging_note', 500)->nullable();
            $table->json('packaging_basis')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_cargo_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gross_weight_grams');
            $table->unsignedInteger('pallet_slots');
            $table->unsignedBigInteger('shared_materials_weight_grams')->nullable();
            $table->char('basis_revision', 64);
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at');
            $table->string('reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_cargo_confirmations');
        Schema::dropIfExists('shipment_assignment_cargos');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('cargo_revision');
        });
        Schema::dropIfExists('vehicle_operations');
        Schema::dropIfExists('vehicles');
        Schema::table('product_packagings', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
