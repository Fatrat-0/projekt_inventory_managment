<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_source_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()
                ->constrained('warehouse_locations', 'id', 'transfer_sources_location_fk')->nullOnDelete();
            // The original placement can be consumed by this transfer.
            $table->unsignedBigInteger('source_placement_id')->nullable();
            $table->string('location_code', 32)->nullable();
            $table->string('location_name', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('cargo_weight_grams')->nullable();
            $table->unsignedInteger('pallet_slots')->default(0);
            $table->boolean('returned_to_location')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_source_allocations');
    }
};
