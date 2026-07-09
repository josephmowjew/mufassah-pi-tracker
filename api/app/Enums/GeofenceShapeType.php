<?php

namespace App\Enums;

enum GeofenceShapeType: string
{
    case Circle = 'circle';
    case Polygon = 'polygon';

    public function label(): string
    {
        return match ($this) {
            self::Circle => 'Circle',
            self::Polygon => 'Polygon',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
