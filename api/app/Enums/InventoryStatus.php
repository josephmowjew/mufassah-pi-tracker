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

    /**
     * Statuses where the unit is physically on hand and still counted as
     * available stock. SOLD is excluded: that unit has already left inventory
     * and its outflow is already recorded on the sale movement, so writing it
     * off again would double-count the reduction.
     *
     * @return array<int, self>
     */
    public static function onHand(): array
    {
        return [self::IN_STOCK, self::RESERVED, self::RETURNED, self::FAULTY];
    }

    public function isOnHand(): bool
    {
        return in_array($this, self::onHand(), true);
    }
}
