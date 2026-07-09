<?php

namespace App\Enums;

enum InventoryStatus: string
{
    case IN_STOCK = 'in_stock';
    case RESERVED = 'reserved';
    case SOLD = 'sold';
    case RETURNED = 'returned';
    case FAULTY = 'faulty';

    public function label(): string
    {
        return match ($this) {
            self::IN_STOCK => 'In Stock',
            self::RESERVED => 'Reserved',
            self::SOLD => 'Sold',
            self::RETURNED => 'Returned',
            self::FAULTY => 'Faulty',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IN_STOCK => 'green',
            self::RESERVED => 'yellow',
            self::SOLD => 'blue',
            self::RETURNED => 'orange',
            self::FAULTY => 'red',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
