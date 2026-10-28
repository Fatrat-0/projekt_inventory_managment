<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_warehouse_id')->constrained('warehouses', 'warehouse_id');
            $table->foreignId('user_id')->constrained('users');
            $table->string('reference', 100);
            $table->string('vehicle_label', 100)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('shipment_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', 'warehouse_id');
            $table->unsignedInteger('position');
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['shipment_id', 'position']);
            $table->unique(['shipment_id', 'warehouse_id']);
        });
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_stop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id');
            $table->unsignedInteger('quantity');
            $table->foreignId('transfer_id')->nullable()->unique()->constrained('inventory_transfers');
            $table->timestamps();
            $table->unique(['shipment_stop_id', 'product_id']);
        });
        Schema::create('inventory_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->foreignId('transfer_id')->nullable()->constrained('inventory_transfers');
            $table->timestamps();
            $table->unique(['user_id', 'request_key'], 'transfer_requests_actor_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_requests');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipment_stops');
        Schema::dropIfExists('shipments');
    }
};
