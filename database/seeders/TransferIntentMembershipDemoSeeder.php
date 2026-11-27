<?php

namespace Database\Seeders;

use App\Models\{User, Warehouse};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferIntentMembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([
                ['manager@raktar.hu', 'manager', 'Központi Elosztó Raktár (Budapest)', 'supervisor'],
                ['worker@raktar.hu', 'worker', 'Déli Regionális Telephely (Pécs)', 'requester'],
            ] as [$email, $globalRole, $warehouseName, $localRole]) {
                $user = User::where('email', $email)->where('role', $globalRole)->first();
                $warehouse = Warehouse::where('warehouse_name', $warehouseName)->first();
                if (!$user || !$warehouse) {
                    throw new RuntimeException('A bemutató tagságokhoz szükség van a DatabaseSeeder által létrehozott tesztfiókokra és raktárakra.');
                }
                $membership = $user->warehouseMemberships()->firstOrCreate(['warehouse_id' => $warehouse->getKey()]);
                if ($membership->wasRecentlyCreated) $membership->syncRoles([$localRole]);
            }
        });
    }
}
