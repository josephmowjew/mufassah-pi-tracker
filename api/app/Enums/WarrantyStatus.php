<?php

namespace App\Enums;

enum WarrantyStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Voided = 'voided';
    case Claimed = 'claimed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Voided => 'Voided',
            self::Claimed => 'Claimed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Expired => 'gray',
            self::Voided => 'red',
            self::Claimed => 'blue',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
