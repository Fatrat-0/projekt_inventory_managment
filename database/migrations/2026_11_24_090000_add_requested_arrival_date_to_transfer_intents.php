<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_intents', function (Blueprint $table) {
            $table->date('requested_arrival_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_intents', function (Blueprint $table) {
            $table->dropColumn('requested_arrival_date');
        });
    }
};
