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
        Schema::create('products', function (Blueprint $table) {
            $table->id('product_id'); // PK, AUTO_INCREMENT 
            $table->string('product_name', 100); // NOT NULL 
            $table->string('sku', 50)->unique(); // Egyedi cikkszám, NOT NULL 
            $table->decimal('price', 10, 2)->default(0); // Ár, >= 0 
            
            // Idegen kulcs a categories táblához 
            $table->foreignId('category_id')->references('category_id')->on('categories')->restrictOnDelete();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
