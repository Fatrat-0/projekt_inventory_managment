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
        return (array_values(self::priority($left)) <=> array_values(self::priority($right))) ?: strcmp($leftCode, $rightCode);
    }

    public static function firstDifference(array $left, array $right): string
    {
        $rightPriority = self::priority($right);
        foreach (self::priority($left) as $criterion => $value) {
            if ($value !== $rightPriority[$criterion]) return $criterion;
        }

        return 'code';
    }

    private static function priority(array $score): array
    {
        return [
            'verified' => $score['verified'] ? 0 : 1,
            'same_product' => $score['same_product'] ? 0 : 1,
            'remaining_pallet_slots' => $score['remaining_pallet_slots'] ?? PHP_INT_MAX,
            'remaining_weight_grams' => $score['remaining_weight_grams'] ?? PHP_INT_MAX,
            'volume_cm3' => $score['volume_cm3'] ?? PHP_INT_MAX,
            'nominal_weight_grams' => $score['nominal_weight_grams'],
        ];
    }
}
