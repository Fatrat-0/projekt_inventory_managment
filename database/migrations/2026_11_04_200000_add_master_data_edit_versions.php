<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'partners', 'warehouses', 'categories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('lock_version')->default(1));
        }
    }

    public function down(): void
    {
        foreach (['products', 'partners', 'warehouses', 'categories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('lock_version'));
        }
    }
};
