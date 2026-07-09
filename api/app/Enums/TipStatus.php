<?php

namespace App\Enums;

enum TipStatus: string
{
    case Pending = 'pending';
    case Reviewing = 'reviewing';
    case Verified = 'verified';
    case FalseLead = 'false_lead';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Reviewing => 'Under Review',
            self::Verified => 'Verified',
            self::FalseLead => 'False Lead',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Reviewing => 'blue',
            self::Verified => 'green',
            self::FalseLead => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
