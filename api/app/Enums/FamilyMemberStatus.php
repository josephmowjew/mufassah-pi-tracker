<?php

namespace App\Enums;

enum FamilyMemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Removed => 'Removed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Removed => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
