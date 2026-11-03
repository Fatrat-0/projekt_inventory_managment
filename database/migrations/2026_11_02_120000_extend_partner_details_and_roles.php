<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->enum('type', ['customer', 'supplier', 'both'])->change();
            $table->string('contact_name', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('street_address', 255)->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('partners')->where('type', 'both')->exists()) {
            throw new RuntimeException('A visszaállítás előtt rendelj egyetlen szerepet a kettős szerepű partnerekhez.');
        }
        Schema::table('partners', function (Blueprint $table) {
            $table->enum('type', ['customer', 'supplier'])->change();
            $table->dropColumn(['contact_name', 'country', 'postal_code', 'city', 'street_address', 'notes']);
        });
    }
};
