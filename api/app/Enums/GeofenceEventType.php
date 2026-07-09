<?php

namespace App\Enums;

enum GeofenceEventType: string
{
    case Entry = 'entry';
    case Exit = 'exit';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entry',
            self::Exit => 'Exit',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
