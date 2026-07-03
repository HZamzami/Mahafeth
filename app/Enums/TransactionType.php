<?php

namespace App\Enums;

enum TransactionType: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Dividend = 'dividend';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Buy => __('Buy'),
            self::Sell => __('Sell'),
            self::Dividend => __('Dividend'),
            self::Deposit => __('Deposit'),
            self::Withdrawal => __('Withdrawal'),
        };
    }
}
