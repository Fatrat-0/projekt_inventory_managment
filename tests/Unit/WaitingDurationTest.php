<?php

namespace Tests\Unit;

use App\Support\WaitingDuration;
use Carbon\{Carbon, CarbonImmutable};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WaitingDurationTest extends TestCase
{
    public static function elapsedTimes(): array
    {
        return [
            'just created' => [0, 'Kevesebb mint egy perce'],
            'under a minute' => [59, 'Kevesebb mint egy perce'],
            'one minute' => [60, '1 perce'],
            'under an hour' => [3599, '59 perce'],
            'one hour' => [3600, '1 órája'],
            'under a day' => [86399, '23 órája'],
            'one day' => [86400, '1 napja'],
            'multiple days' => [3 * 86400 + 3599, '3 napja'],
        ];
    }

    #[DataProvider('elapsedTimes')]
    public function test_only_completed_elapsed_units_are_shown_for_both_workflows(int $seconds, string $label): void
    {
        $asOf = CarbonImmutable::parse('2026-11-05 12:00:00', 'Europe/Budapest');
        foreach (['úton', 'minősítésre vár'] as $state) {
            $this->assertSame($label.' '.$state, WaitingDuration::label($asOf->subSeconds($seconds), $asOf, $state));
        }
    }

    public static function clockBoundaries(): array
    {
        return [
            'midnight' => ['2026-11-04 23:59:00', '2026-11-05 00:01:00', '2 perce úton'],
            'month boundary' => ['2026-10-31 23:30:00', '2026-11-01 00:30:00', '1 órája úton'],
            'spring clock change' => ['2026-03-29 01:30:00', '2026-03-29 03:30:00', '1 órája úton'],
            'short calendar day' => ['2026-03-29 00:00:00', '2026-03-30 00:00:00', '23 órája úton'],
            'long calendar day' => ['2026-10-25 00:00:00', '2026-10-26 00:00:00', '1 napja úton'],
            'future' => ['2026-11-05 12:00:01', '2026-11-05 12:00:00', 'Jövőbeli időpont – ellenőrzendő'],
        ];
    }

    #[DataProvider('clockBoundaries')]
    public function test_clock_boundaries_use_elapsed_time_and_future_dates_are_not_negative(string $since, string $asOf, string $label): void
    {
        $this->assertSame($label, WaitingDuration::label(CarbonImmutable::parse($since, 'Europe/Budapest'), CarbonImmutable::parse($asOf, 'Europe/Budapest'), 'úton'));
    }

    public function test_different_timezones_compare_instants_and_mutable_dates_are_not_changed(): void
    {
        $since = Carbon::parse('2026-11-05 11:00:00', 'UTC');
        $asOf = Carbon::parse('2026-11-05 12:00:00', 'Europe/Budapest');
        $before = [$since->toIso8601String(), $asOf->toIso8601String()];
        $this->assertSame('Kevesebb mint egy perce úton', WaitingDuration::label($since, $asOf, 'úton'));
        $this->assertSame($before, [$since->toIso8601String(), $asOf->toIso8601String()]);
    }
}
