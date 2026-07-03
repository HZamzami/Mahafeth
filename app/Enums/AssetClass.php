<?php

namespace App\Enums;

enum AssetClass: string
{
    case Equity = 'equity';
    case Fund = 'fund';
    case Bond = 'bond';
    case Crypto = 'crypto';
    case RealEstate = 'real_estate';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Equity => __('Equities'),
            self::Fund => __('Funds'),
            self::Bond => __('Bonds'),
            self::Crypto => __('Crypto'),
            self::RealEstate => __('Real Estate'),
            self::Cash => __('Cash'),
        };
    }
}
