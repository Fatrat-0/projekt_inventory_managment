<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            // Melyik terméket?
            $table->foreignId('product_id')->constrained('products', 'product_id');
            // Honnan?
            $table->foreignId('from_warehouse_id')->constrained('warehouses', 'warehouse_id');
            // Hová?
            $table->foreignId('to_warehouse_id')->constrained('warehouses', 'warehouse_id');
            // Mennyit?
            $table->integer('quantity');
            // Mi a státusza? (alapértelmezetten 'pending' = úton lévő)
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            // Ki indította?
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
