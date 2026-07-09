<?php

namespace App\Enums;

enum IotModuleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Tampered = 'tampered';
    case Offline = 'offline';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Tampered => 'Tampered',
            self::Offline => 'Offline',
            self::Maintenance => 'Maintenance',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Tampered => 'red',
            self::Offline => 'orange',
            self::Maintenance => 'yellow',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function canTransmitLocation(): bool
    {
        return in_array($this, [self::Active, self::Offline]);
    }
}
