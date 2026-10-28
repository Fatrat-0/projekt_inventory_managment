<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Existing history cannot be reconstructed reliably, so legacy values stay null.
            $table->unsignedInteger('stock_before')->nullable();
            $table->unsignedInteger('stock_after')->nullable();
        });
        Schema::create('inventory_movement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements');
            $table->timestamps();
            $table->unique(['user_id', 'request_key'], 'movement_requests_actor_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_requests');
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['stock_before', 'stock_after']);
        });
    }
};
