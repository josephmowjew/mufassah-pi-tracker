<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Pending => 'Pending',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Cancelled => 'gray',
            self::Expired => 'red',
            self::Suspended => 'orange',
            self::Pending => 'yellow',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
