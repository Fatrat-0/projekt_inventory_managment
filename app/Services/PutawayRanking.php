<?php

namespace App\Services;

final class PutawayRanking
{
    /**
     * @param array{verified: bool, same_product: bool, remaining_pallet_slots: ?int, remaining_weight_grams: ?int, volume_cm3: ?int, nominal_weight_grams: int} $left
     * @param array{verified: bool, same_product: bool, remaining_pallet_slots: ?int, remaining_weight_grams: ?int, volume_cm3: ?int, nominal_weight_grams: int} $right
     */
    public static function compare(array $left, array $right, string $leftCode, string $rightCode): int
    {
        return (self::priority($left) <=> self::priority($right)) ?: strcmp($leftCode, $rightCode);
    }

    private static function priority(array $score): array
    {
        return [
            $score['verified'] ? 0 : 1,
            $score['same_product'] ? 0 : 1,
            $score['remaining_pallet_slots'] ?? PHP_INT_MAX,
            $score['remaining_weight_grams'] ?? PHP_INT_MAX,
            $score['volume_cm3'] ?? PHP_INT_MAX,
            $score['nominal_weight_grams'],
        ];
    }
}
