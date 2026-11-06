<?php

namespace App\Support;

use Carbon\CarbonInterface;

class WaitingDuration
{
    public static function label(CarbonInterface $since, CarbonInterface $asOf, string $state): string
    {
        // Completed elapsed units, including across midnight and daylight-saving changes.
        $seconds = $asOf->getTimestamp() - $since->getTimestamp();
        if ($seconds < 0) return 'Jövőbeli időpont – ellenőrzendő';
        if ($seconds < 60) return 'Kevesebb mint egy perce '.$state;
        [$amount, $unit] = match (true) {
            $seconds < 3600 => [intdiv($seconds, 60), 'perce'],
            $seconds < 86400 => [intdiv($seconds, 3600), 'órája'],
            default => [intdiv($seconds, 86400), 'napja'],
        };

        return number_format($amount, 0, ',', ' ').' '.$unit.' '.$state;
    }
}
