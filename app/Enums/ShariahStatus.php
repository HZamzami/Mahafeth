<?php

namespace App\Enums;

enum ShariahStatus: string
{
    case Compliant = 'compliant';
    case NonCompliant = 'non_compliant';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Compliant => __('Shariah Compliant'),
            self::NonCompliant => __('Not Shariah Compliant'),
            self::Unknown => __('Compliance Unknown'),
        };
    }
}
