<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money',
            self::BankTransfer => 'Bank Transfer',
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Credit => 'Credit/On Account',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'green',
            self::MobileMoney => 'blue',
            self::Card => 'purple',
            self::BankTransfer => 'cyan',
            self::Credit => 'orange',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
