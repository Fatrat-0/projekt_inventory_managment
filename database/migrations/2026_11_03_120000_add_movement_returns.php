<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('return_of_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->string('return_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['return_of_id']);
            $table->dropColumn(['return_of_id', 'return_reason']);
        });
    }
};
