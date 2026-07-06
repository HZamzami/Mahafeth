<?php

namespace App\Enums;

enum AccountType: string
{
    case Brokerage = 'brokerage';
    case Retirement = 'retirement';
    case Crypto = 'crypto';
    case Fund = 'fund';
    case Savings = 'savings';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Brokerage => __('Brokerage Account'),
            self::Retirement => __('Retirement Account'),
            self::Crypto => __('Crypto Wallet'),
            self::Fund => __('Fund Account'),
            self::Savings => __('Savings Account'),
            self::Cash => __('Current Account'),
        };
    }
}
