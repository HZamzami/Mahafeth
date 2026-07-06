<?php

namespace App\Enums;

enum ConsentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
        };
    }
}
