<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products', 'product_id')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('pieces_per_package');
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->decimal('width_cm', 7, 2)->nullable();
            $table->decimal('depth_cm', 7, 2)->nullable();
            $table->decimal('height_cm', 7, 2)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packagings');
    }
};
