<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    // Listed last on purpose. MySQL can widen an ENUM in place when the new
    // member is appended to the end, but a member inserted mid-list forces a
    // table rebuild. Keep this case at the bottom of the declaration.
    case RefundProcessing = 'refund_processing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::RefundProcessing => 'Refund In Progress',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Refunded => 'blue',
            self::Cancelled => 'gray',
            self::RefundProcessing => 'orange',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
