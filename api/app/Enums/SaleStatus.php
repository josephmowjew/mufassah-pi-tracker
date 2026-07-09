<?php

namespace App\Enums;

enum SaleStatus: string
{
    case DRAFT = 'draft';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::REFUNDED => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'yellow',
            self::COMPLETED => 'green',
            self::CANCELLED => 'gray',
            self::REFUNDED => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
