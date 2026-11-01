<?php

namespace App\Http\Requests\Concerns;

use App\Support\LocalTime;

trait NormalizesUtcDates
{
    protected function normalizeUtcDateInput(string $field): void
    {
        $date = $this->input($field);

        // Leave invalid/overflowing dates to the normal validation rules.
        // Parsing first could turn February 31 into a valid date in March.
        if (is_string($date) && validator(['date' => $date], ['date' => 'date'])->passes()) {
            $this->merge([$field => LocalTime::dateOrInstantUtc($date)->toDateTimeString()]);
        }
    }
}
