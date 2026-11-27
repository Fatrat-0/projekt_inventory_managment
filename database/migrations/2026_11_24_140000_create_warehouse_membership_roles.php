<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLES = ['requester', 'dispatch_operator', 'receiving_operator', 'supervisor'];

    public function up(): void
    {
        // MySQL commits DDL separately. A rerun after an interrupted backfill can resume here.
        if (!Schema::hasTable('warehouse_membership_roles')) {
            Schema::create('warehouse_membership_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_membership_id')->constrained()->cascadeOnDelete();
                $table->enum('role', self::ROLES);
                $table->unique(['warehouse_membership_id', 'role'], 'warehouse_membership_roles_unique');
                $table->index(['role', 'warehouse_membership_id'], 'warehouse_membership_roles_lookup');
            });
        }

        if (Schema::hasColumn('warehouse_memberships', 'role')) {
            if (DB::table('warehouse_memberships')->whereNull('role')->orWhereNotIn('role', self::ROLES)->exists()) {
                throw new RuntimeException('A raktári tagságok között ismeretlen helyi szerepkör található.');
            }

            DB::transaction(function () {
                // Until the old column is removed it is authoritative, including after a failed cutover.
                DB::table('warehouse_membership_roles')->whereExists(fn ($memberships) => $memberships->selectRaw('1')
                    ->from('warehouse_memberships as membership')->whereColumn('membership.id', 'warehouse_membership_roles.warehouse_membership_id')
                    ->whereColumn('membership.role', '<>', 'warehouse_membership_roles.role'))->delete();
                DB::table('warehouse_membership_roles')->insertUsing(['warehouse_membership_id', 'role'],
                    DB::table('warehouse_memberships as membership')->select('membership.id', 'membership.role')
                        ->whereNotExists(fn ($roles) => $roles->selectRaw('1')->from('warehouse_membership_roles as assignment')
                            ->whereColumn('assignment.warehouse_membership_id', 'membership.id')
                            ->whereColumn('assignment.role', 'membership.role')));
            });

            Schema::table('warehouse_memberships', fn (Blueprint $table) => $table->dropColumn('role'));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('warehouse_membership_roles') && Schema::hasColumn('warehouse_memberships', 'role')) return;

        if (DB::table('warehouse_memberships as membership')->whereRaw(
            '(select count(*) from warehouse_membership_roles where warehouse_membership_id = membership.id) <> 1'
        )->exists()) {
            throw new RuntimeException('A visszagörgetéshez minden raktári tagságnak pontosan egy szerepkörrel kell rendelkeznie.');
        }

        if (!Schema::hasColumn('warehouse_memberships', 'role')) {
            Schema::table('warehouse_memberships', fn (Blueprint $table) => $table->enum('role', self::ROLES)->nullable());
        }

        DB::table('warehouse_memberships')->update(['role' => DB::table('warehouse_membership_roles')->select('role')
            ->whereColumn('warehouse_membership_id', 'warehouse_memberships.id')]);
        Schema::table('warehouse_memberships', fn (Blueprint $table) => $table->enum('role', self::ROLES)->change());
        Schema::drop('warehouse_membership_roles');
    }
};
