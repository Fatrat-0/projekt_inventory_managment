<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_storage_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unique(['warehouse_id', 'name']);
        });
        Schema::create('warehouse_storage_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('warehouse_storage_zones')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('kind', 16);
            $table->unsignedInteger('max_weight_grams');
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedTinyInteger('floor_x');
            $table->unsignedTinyInteger('floor_y');
            $table->unsignedTinyInteger('floor_width');
            $table->unsignedTinyInteger('floor_height');
            $table->timestamps();
        });
        Schema::create('warehouse_storage_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained('warehouse_storage_systems')->restrictOnDelete();
            $table->unsignedTinyInteger('number');
            $table->unsignedInteger('max_weight_grams');
            $table->unique(['system_id', 'number']);
        });
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->foreignId('system_id')->nullable()->constrained('warehouse_storage_systems')->restrictOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('warehouse_storage_levels')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropForeign(['system_id']);
            $table->dropForeign(['level_id']);
        });
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropColumn(['system_id', 'level_id']);
        });
        Schema::dropIfExists('warehouse_storage_levels');
        Schema::dropIfExists('warehouse_storage_systems');
        Schema::dropIfExists('warehouse_storage_zones');
    }
};
