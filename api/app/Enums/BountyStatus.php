<?php

namespace App\Enums;

enum BountyStatus: string
{
    case None = 'none';
    case Offered = 'offered';
    case Claimed = 'claimed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No Bounty',
            self::Offered => 'Bounty Offered',
            self::Claimed => 'Claimed',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Offered => 'amber',
            self::Claimed => 'blue',
            self::Paid => 'green',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function hasBounty(): bool
    {
        return $this !== self::None;
    }
}
