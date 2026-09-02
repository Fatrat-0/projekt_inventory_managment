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
        Schema::table('categories', function (Blueprint $table) {
            // 1. Szülő kategória hozzáadása
            if (!Schema::hasColumn('categories', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->foreign('parent_id')->references('category_id')->on('categories')->nullOnDelete();
            }

            // 2. Alapértelmezett minimum készlet
            if (!Schema::hasColumn('categories', 'default_item_min_stock')) {
                $table->integer('default_item_min_stock')->nullable();
            }

            // 3. Összesített minimum készlet
            if (!Schema::hasColumn('categories', 'aggregate_min_stock')) {
                $table->integer('aggregate_min_stock')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
            if (Schema::hasColumn('categories', 'default_item_min_stock')) {
                $table->dropColumn('default_item_min_stock');
            }
            if (Schema::hasColumn('categories', 'aggregate_min_stock')) {
                $table->dropColumn('aggregate_min_stock');
            }
        });
    }
};
