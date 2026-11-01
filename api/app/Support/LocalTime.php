<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/** Business calendar boundaries are local; database query parameters are UTC. */
final class LocalTime
{
    public static function now(): Carbon
    {
        return Carbon::now(config('app.local_timezone'));
    }

    /** Machine timestamps and instant filters use UTC when no offset is supplied. */
    public static function instantUtc(string|DateTimeInterface $value): Carbon
    {
        return Carbon::parse($value, 'UTC')->utc();
    }

    /** Date-only input means local midnight; naive datetimes retain legacy UTC semantics. */
    public static function dateOrInstantUtc(string $value): Carbon
    {
        $value = trim($value);
        $parts = date_parse($value);
        $timezone = $parts['hour'] === false
            ? config('app.local_timezone')
            : 'UTC';

        return Carbon::parse($value, $timezone)->utc();
    }

    public static function startOfDayUtc(string $date): Carbon
    {
        return Carbon::parse($date, config('app.local_timezone'))
            ->setTimezone(config('app.local_timezone'))->startOfDay()->utc();
    }

    public static function endOfDayUtc(string $date): Carbon
    {
        return Carbon::parse($date, config('app.local_timezone'))
            ->setTimezone(config('app.local_timezone'))->endOfDay()->utc();
    }
}
