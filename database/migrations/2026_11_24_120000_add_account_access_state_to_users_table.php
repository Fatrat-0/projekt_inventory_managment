<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedInteger('access_version')->default(0);
            $table->index(['role', 'deactivated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'deactivated_at']);
            $table->dropColumn(['deactivated_at', 'access_version']);
        });
    }
};
