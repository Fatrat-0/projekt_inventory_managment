<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeArrivalColumn(true);
    }

    public function down(): void
    {
        if (DB::table('transfer_intents')->whereNull('requested_arrival_at')->exists()) {
            throw new RuntimeException('A határidő kötelezővé tételéhez minden igényhez meg kell adni a kért érkezési időt.');
        }

        $this->changeArrivalColumn(false);
    }

    private function changeArrivalColumn(bool $nullable): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('transfer_intents', function (Blueprint $table) use ($nullable) {
                $table->dateTime('requested_arrival_at')->nullable($nullable)->change();
            });

            return;
        }

        // Laravel rebuilds the SQLite table for a column change. Preserve its validation rules.
        Schema::withoutForeignKeyConstraints(function () use ($nullable) {
            DB::transaction(function () use ($nullable) {
                $triggers = DB::table('sqlite_master')->where('type', 'trigger')
                    ->where('tbl_name', 'transfer_intents')->pluck('sql');
                Schema::table('transfer_intents', function (Blueprint $table) use ($nullable) {
                    $table->dateTime('requested_arrival_at')->nullable($nullable)->change();
                    $table->enum('priority', ['normal', 'urgent', 'critical'])->default('normal')->change();
                    $table->enum('shipping_preference', ['groupable', 'dedicated_requested'])->default('groupable')->change();
                    $table->enum('status', ['pending_approval', 'approved', 'rejected', 'cancelled'])->default('pending_approval')->change();
                });
                foreach ($triggers as $trigger) DB::statement($trigger);
            });
        });
    }
};
