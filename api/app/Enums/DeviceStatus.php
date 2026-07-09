<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case ACTIVE = 'active';
    case STOLEN = 'stolen';
    case RECOVERED = 'recovered';
    case INACTIVE = 'inactive';
    case MAINTENANCE = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::STOLEN => 'Stolen',
            self::RECOVERED => 'Recovered',
            self::INACTIVE => 'Inactive',
            self::MAINTENANCE => 'Maintenance',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'green',
            self::STOLEN => 'red',
            self::RECOVERED => 'blue',
            self::INACTIVE => 'gray',
            self::MAINTENANCE => 'yellow',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
