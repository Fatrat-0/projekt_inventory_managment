<?php

namespace App\Support;

use App\Models\{InventoryTransfer, User};
use App\Queries\ReturnQualityListing;

class AppTasks
{
    public static function summary(?User $user): ?array
    {
        if (!in_array($user?->role, ['admin', 'manager', 'worker'], true)) return null;
        $transfers = InventoryTransfer::where('status', 'pending')->count();
        $returns = ReturnQualityListing::pendingSummary();
        return ['transfers' => $transfers, 'returns' => $returns['returns_count'], 'returnQuantity' => $returns['quantity'], 'total' => $transfers + $returns['returns_count']];
    }
}
