<?php

namespace App\Enums;

enum ClaimStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Paid => 'green',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isFinalized(): bool
    {
        return in_array($this, [self::Rejected, self::Paid]);
    }
}
