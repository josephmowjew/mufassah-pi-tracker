<?php

namespace App\Enums;

enum TheftReportStatus: string
{
    case Reported = 'reported';
    case Investigating = 'investigating';
    case Recovered = 'recovered';
    case Closed = 'closed';
    case FalseAlarm = 'false_alarm';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::Investigating => 'Investigating',
            self::Recovered => 'Recovered',
            self::Closed => 'Closed',
            self::FalseAlarm => 'False Alarm',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reported => 'red',
            self::Investigating => 'orange',
            self::Recovered => 'green',
            self::Closed => 'gray',
            self::FalseAlarm => 'yellow',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
