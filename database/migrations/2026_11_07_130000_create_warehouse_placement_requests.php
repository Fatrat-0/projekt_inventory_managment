<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_placement_requests', function (Blueprint $table) {
            $table->uuid('request_key')->primary();
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_placement_requests');
    }
};
