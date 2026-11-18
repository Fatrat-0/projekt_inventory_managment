<?php

namespace Tests\Unit;

use App\Services\PutawayRanking;
use PHPUnit\Framework\TestCase;

class PutawayRankingTest extends TestCase
{
    public function test_uses_verification_then_product_then_capacity_then_size_then_code(): void
    {
        $score = ['verified' => false, 'same_product' => false, 'remaining_pallet_slots' => 2, 'remaining_weight_grams' => 10000,
            'volume_cm3' => 1000, 'nominal_weight_grams' => 50000];

        $verified = ['verified' => true, 'remaining_weight_grams' => 300000] + $score;
        $conditional = ['same_product' => true, 'remaining_weight_grams' => 0] + $score;
        $this->assertLessThan(0, PutawayRanking::compare($verified, $conditional, 'Z', 'A'));

        foreach ([
            ['same_product' => true, 'remaining_pallet_slots' => 10],
            ['remaining_pallet_slots' => 0, 'remaining_weight_grams' => 20000],
            ['remaining_weight_grams' => 0, 'volume_cm3' => 2000],
            ['volume_cm3' => 500],
            ['nominal_weight_grams' => 40000],
        ] as $preferred) {
            $this->assertLessThan(0, PutawayRanking::compare($preferred + $score, $score, 'Z', 'A'));
        }
        $this->assertLessThan(0, PutawayRanking::compare($score, $score, 'A-02', 'A-10'));
        $this->assertGreaterThan(0, PutawayRanking::compare(['remaining_weight_grams' => null] + $score, $score, 'A', 'Z'));
    }
}
