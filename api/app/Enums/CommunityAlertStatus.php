<?php

namespace App\Enums;

enum CommunityAlertStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Resolved = 'resolved';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Resolved => 'Resolved',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'red',
            self::Paused => 'yellow',
            self::Resolved => 'green',
            self::Expired => 'gray',
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
}
