<?php

namespace App\Enums;

enum InstitutionType: string
{
    case Brokerage = 'brokerage';
    case Bank = 'bank';
    case CryptoExchange = 'crypto_exchange';
    case FundPlatform = 'fund_platform';

    public function label(): string
    {
        return match ($this) {
            self::Brokerage => __('Brokerage'),
            self::Bank => __('Bank'),
            self::CryptoExchange => __('Crypto Exchange'),
            self::FundPlatform => __('Fund Platform'),
        };
    }
}
