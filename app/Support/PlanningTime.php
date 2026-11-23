<?php

namespace App\Support;

use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Validation\ValidationException;

final class PlanningTime
{
    public const ZONE = 'Europe/Budapest';

    public const FORMAT = 'regex:/^\d{4}-\d{2}-\d{2}(?:[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?$/D';

    public const PAST_ARRIVAL = 'A kért érkezési határidő nem lehet múltbeli.';

    public const PAST_DEPARTURE = 'A tervezett indulás nem lehet múltbeli időpont.';

    public static function localMinimum(?CarbonInterface $at = null): string
    {
        return ($at ?? now())->avoidMutation()->setTimezone(self::ZONE)->format('Y-m-d\TH:i');
    }

    public static function parse(?string $value, string $field, string $rangeError): ?CarbonImmutable
    {
        if ($value === null) return null;

        // Offsetless API values have always used the storage timezone. The local UI sends an explicit offset.
        $time = CarbonImmutable::parse($value, 'UTC')->utc();
        if ($time->year < 1000 || $time->year > 9999) {
            throw ValidationException::withMessages([$field => $rangeError]);
        }

        return $time;
    }

    public static function isPast(?CarbonInterface $time, ?CarbonInterface $at = null): bool
    {
        return $time !== null && $time->lt(($at ?? now())->avoidMutation()->startOfMinute());
    }
}
