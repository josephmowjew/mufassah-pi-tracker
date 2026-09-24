<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case SENT = 'sent';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Active',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
            self::CONVERTED => 'Redeemed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SENT => 'green',
            self::EXPIRED => 'amber',
            self::CANCELLED => 'red',
            self::CONVERTED => 'blue',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
