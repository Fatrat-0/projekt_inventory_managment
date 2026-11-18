<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_intent_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('operation_key')->unique();

            $table->index(['transfer_intent_id', 'occurred_at', 'id'], 'transfer_workflow_events_intent_timeline_idx');
            $table->index('actor_id');
        });

        $sqlite = DB::getDriverName() === 'sqlite';
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $trigger = 'transfer_workflow_events_block_'.strtolower($operation);
            $message = 'A munkafolyamat-napló bejegyzései csak hozzáfűzhetők.';
            $body = $sqlite
                ? "BEGIN SELECT RAISE(ABORT, '$message'); END"
                : "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'";

            DB::statement("CREATE TRIGGER $trigger BEFORE $operation ON transfer_workflow_events $body");
        }

        if ($sqlite) {
            // SQLite REPLACE can skip DELETE triggers. Keep duplicate errors recognizable as unique violations.
            DB::statement(<<<'SQL'
                CREATE TRIGGER transfer_workflow_events_block_replacement
                BEFORE INSERT ON transfer_workflow_events
                BEGIN
                    SELECT CASE WHEN EXISTS (SELECT 1 FROM transfer_workflow_events WHERE id = NEW.id)
                        THEN RAISE(ABORT, 'UNIQUE constraint failed: transfer_workflow_events.id') END;
                    SELECT CASE WHEN EXISTS (SELECT 1 FROM transfer_workflow_events WHERE operation_key = NEW.operation_key)
                        THEN RAISE(ABORT, 'UNIQUE constraint failed: transfer_workflow_events.operation_key') END;
                END
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_workflow_events');
    }
};
