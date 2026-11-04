<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedInteger('quarantined_quantity')->default(0);
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Historical returns remain available; only new returns start quarantined.
            $table->unsignedInteger('quarantined_quantity')->default(0);
            $table->foreignId('scrap_of_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
        });
        Schema::create('return_quality_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 10);
            $table->unsignedInteger('quantity');
            $table->string('reason', 500);
            $table->uuid('request_key')->nullable();
            $table->string('payload_hash', 64);
            $table->unsignedInteger('stock_before');
            $table->unsignedInteger('stock_after');
            $table->unsignedInteger('quarantined_before');
            $table->unsignedInteger('quarantined_after');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_quality_actions');
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['scrap_of_id']);
            $table->dropColumn(['scrap_of_id', 'quarantined_quantity']);
        });
        Schema::table('stocks', fn (Blueprint $table) => $table->dropColumn('quarantined_quantity'));
    }
};
