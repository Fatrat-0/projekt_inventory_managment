<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('kind', 16);
            $table->unsignedInteger('max_weight_grams');
            $table->unsignedInteger('max_pallet_slots')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('warehouse_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('cargo_weight_grams');
            $table->unsignedInteger('pallet_slots')->default(0);
            $table->timestamps();
        });

        foreach (['inventory_transfers', 'shipment_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
                $table->unsignedInteger('cargo_weight_grams')->nullable();
                $table->unsignedInteger('pallet_slots')->default(0);
            });
        }
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->index(['to_location_id', 'status'], 'transfers_location_status_index');
        });
    }

    public function down(): void
    {
        foreach (['shipment_items', 'inventory_transfers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['to_location_id']);
            });
        }
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropIndex('transfers_location_status_index');
        });
        foreach (['shipment_items', 'inventory_transfers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['to_location_id', 'cargo_weight_grams', 'pallet_slots']);
            });
        }
        Schema::dropIfExists('warehouse_placements');
        Schema::dropIfExists('warehouse_locations');
    }
};
