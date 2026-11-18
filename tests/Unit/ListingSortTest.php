<?php

namespace Tests\Unit;

use App\Support\ListingSort;
use PHPUnit\Framework\TestCase;

class ListingSortTest extends TestCase
{
    private const SORTS = ['priority' => 'Prioritás', 'quantity' => 'Mennyiség', 'created_at' => 'Létrehozva'];

    public function test_optional_secondary_is_absent_by_default_and_unrelated_filters_survive(): void
    {
        $filters = ListingSort::normalize(['search' => 'csavar', 'per_page' => 15, 'then_dir' => 'desc'], self::SORTS, 'created_at');
        $this->assertSame(['search' => 'csavar', 'per_page' => 15, 'sort_by' => 'created_at', 'sort_dir' => 'desc'], $filters);
    }

    public function test_two_levels_have_independent_directions_and_never_overwrite_primary(): void
    {
        $this->assertSame([
            ['field' => 'priority', 'direction' => 'desc'],
            ['field' => 'quantity', 'direction' => 'asc'],
        ], ListingSort::levels(['sort_by' => 'priority', 'sort_dir' => 'desc', 'then_by' => 'quantity'], self::SORTS, 'created_at'));
    }

    public function test_duplicate_secondary_is_removed(): void
    {
        $this->assertSame([['field' => 'priority', 'direction' => 'asc']], ListingSort::levels([
            'sort_by' => 'priority', 'sort_dir' => 'asc', 'then_by' => 'priority', 'then_dir' => 'desc',
        ], self::SORTS, 'created_at'));
    }

    public function test_unvalidated_malformed_fields_and_directions_are_safe_at_service_boundary(): void
    {
        foreach ([null, [], ['quantity'], 'unknown; DROP TABLE stocks'] as $unsafe) {
            $this->assertSame([['field' => 'created_at', 'direction' => 'desc']], ListingSort::levels([
                'sort_by' => $unsafe, 'sort_dir' => $unsafe, 'then_by' => $unsafe, 'then_dir' => $unsafe,
            ], self::SORTS, 'created_at'));
        }
    }
}
