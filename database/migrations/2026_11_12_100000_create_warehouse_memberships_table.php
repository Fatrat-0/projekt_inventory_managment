<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id')->restrictOnDelete();
            $table->enum('role', ['requester', 'dispatch_operator', 'receiving_operator', 'supervisor']);
            $table->timestamps();

            $table->unique(['user_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_memberships');
    }
};
