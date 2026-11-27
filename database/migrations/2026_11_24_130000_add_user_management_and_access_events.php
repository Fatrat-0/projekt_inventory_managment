<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
            $table->boolean('password_change_required')->default(false);
        });
        Schema::create('user_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->json('changes');
            $table->timestamp('occurred_at');
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['lock_version', 'password_change_required']));
    }
};
