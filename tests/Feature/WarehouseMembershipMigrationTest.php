<?php

namespace Tests\Feature;

use App\Models\{User, Warehouse, WarehouseMembership};
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use RuntimeException;
use Tests\TestCase;

class WarehouseMembershipMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_memberships_keep_their_id_timestamps_and_exactly_one_original_role(): void
    {
        $migration = require database_path('migrations/2026_11_24_140000_create_warehouse_membership_roles.php');
        $migration->down();
        $user = User::factory()->create(['role' => 'worker']);
        $original = [];
        foreach (WarehouseMembership::ROLES as $role => $label) {
            $row = ['user_id' => $user->id, 'warehouse_id' => Warehouse::factory()->create()->getKey(), 'role' => $role,
                'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-02-04 11:15:00'];
            $row['id'] = DB::table('warehouse_memberships')->insertGetId($row);
            $original[] = $row;
        }

        $migration->up();
        $this->assertFalse(Schema::hasColumn('warehouse_memberships', 'role'));
        $this->assertDatabaseCount('warehouse_memberships', 4);
        $this->assertDatabaseCount('warehouse_membership_roles', 4);
        foreach ($original as $row) {
            $this->assertDatabaseHas('warehouse_memberships', array_diff_key($row, ['role' => true]));
            $this->assertSame([$row['role']], WarehouseMembership::findOrFail($row['id'])->roleCodes());
        }

        // Simulate a cutover interrupted before dropping the column, followed by a legacy edit.
        Schema::table('warehouse_memberships', fn (Blueprint $table) => $table->string('role')->nullable());
        $original[0]['role'] = 'receiving_operator';
        foreach ($original as $row) {
            DB::table('warehouse_memberships')->where('id', $row['id'])->update(['role' => $row['role']]);
        }
        $migration->up();
        $this->assertDatabaseCount('warehouse_membership_roles', 4);
        $this->assertSame(['receiving_operator'], WarehouseMembership::findOrFail($original[0]['id'])->roleCodes());

        $this->expectException(QueryException::class);
        DB::table('warehouse_membership_roles')->insert(['warehouse_membership_id' => $original[0]['id'], 'role' => $original[0]['role']]);
    }

    public function test_rollback_preserves_role_sets_by_refusing_to_collapse_a_multi_role_membership(): void
    {
        $migration = require database_path('migrations/2026_11_24_140000_create_warehouse_membership_roles.php');
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->create(['role' => 'worker']);
        $membership = $user->addWarehouseMembership($warehouse->getKey(), ['dispatch_operator', 'receiving_operator']);

        try {
            $migration->down();
            $this->fail('A több szerepkörös tagság összevonása adatvesztést okozna.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pontosan egy szerepkörrel', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('warehouse_memberships', 'role'));
        $this->assertSame(['dispatch_operator', 'receiving_operator'], $membership->roleCodes());
    }
}
