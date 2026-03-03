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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id(); // PK
            
            // Idegen kulcsok a raktárhoz és a termékhez
            $table->foreignId('warehouse_id')->references('warehouse_id')->on('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->references('product_id')->on('products')->restrictOnDelete();
            
            // Készlet adatok
            $table->integer('quantity')->default(0); // Aktuális mennyiség
            $table->integer('min_quantity')->default(5); // Riasztási küszöb
            
            $table->timestamps();

            // Egy termék egy raktárban csak egyszer szerepelhet!
            $table->unique(['warehouse_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
