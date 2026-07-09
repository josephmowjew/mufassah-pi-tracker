<?php

namespace App\Enums;

enum WarrantyClaimStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Processing => 'blue',
            self::Completed => 'gray',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
