<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor_columns')->default(24);
            $table->unsignedTinyInteger('floor_rows')->default(16);
            $table->unsignedInteger('floor_version')->default(1);
        });

        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor_x')->nullable();
            $table->unsignedTinyInteger('floor_y')->nullable();
            $table->unsignedTinyInteger('floor_width')->nullable();
            $table->unsignedTinyInteger('floor_height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropColumn(['floor_x', 'floor_y', 'floor_width', 'floor_height']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['floor_columns', 'floor_rows', 'floor_version']);
        });
    }
};
