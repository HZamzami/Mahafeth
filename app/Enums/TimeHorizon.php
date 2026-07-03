<?php

namespace App\Enums;

enum TimeHorizon: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';
    case VeryLong = 'very_long';

    public function label(): string
    {
        return match ($this) {
            self::Short => __('Under 3 years'),
            self::Medium => __('3–7 years'),
            self::Long => __('7–15 years'),
            self::VeryLong => __('Over 15 years'),
        };
    }
}
