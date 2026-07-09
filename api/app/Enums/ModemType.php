<?php

namespace App\Enums;

enum ModemType: string
{
    case Gps = 'GPS';
    case Gsm = 'GSM';
    case GpsGsm = 'GPS_GSM';
    case Wifi = 'WIFI';

    public function label(): string
    {
        return match ($this) {
            self::Gps => 'GPS Only',
            self::Gsm => 'GSM Only',
            self::GpsGsm => 'GPS + GSM',
            self::Wifi => 'WiFi',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
