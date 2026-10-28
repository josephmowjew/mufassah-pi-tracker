<?php

namespace App\Enums;

enum ShiftBreakType: string
{
    case LUNCH = 'lunch';
    case TEA = 'tea';
    case COMFORT = 'comfort';
    case PRAYER = 'prayer';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LUNCH => 'Lunch',
            self::TEA => 'Tea break',
            self::COMFORT => 'Comfort',
            self::PRAYER => 'Prayer',
            self::OTHER => 'Other',
        };
    }

    /**
     * The longest break that is still expected to be unpaid downtime, in
     * minutes. Used only to flag outliers in reporting — never to reject a
     * break, because a genuinely long break is a fact worth recording.
     */
    public function typicalMinutes(): ?int
    {
        return match ($this) {
            self::LUNCH => 60,
            self::TEA => 15,
            self::COMFORT => 10,
            self::PRAYER => 20,
            self::OTHER => null,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
