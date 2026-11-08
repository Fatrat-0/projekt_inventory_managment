<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_storage_systems', function (Blueprint $table) {
            $table->string('code', 24)->nullable();
            $table->unsignedSmallInteger('front_width_cm')->nullable();
            $table->unsignedSmallInteger('depth_cm')->nullable();
            $table->unique(['zone_id', 'code']);
        });
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->unsignedTinyInteger('bin_number')->nullable();
            $table->unsignedSmallInteger('width_cm')->nullable();
            $table->unsignedSmallInteger('depth_cm')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
        });
        Schema::create('warehouse_location_categories', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->foreign('category_id')->references('category_id')->on('categories')->restrictOnDelete();
            $table->primary(['location_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_location_categories');
        Schema::table('warehouse_locations', fn (Blueprint $table) => $table->dropColumn(['bin_number', 'width_cm', 'depth_cm', 'height_cm']));
        Schema::table('warehouse_storage_systems', function (Blueprint $table) {
            $table->dropUnique(['zone_id', 'code']);
            $table->dropColumn(['code', 'front_width_cm', 'depth_cm']);
        });
    }
};
