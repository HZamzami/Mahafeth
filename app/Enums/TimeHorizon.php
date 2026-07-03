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

    /**
     * Trailing price-history window (in years) the analytics engine uses
     * for an investor with this horizon. Longer horizons look further back.
     */
    public function analysisWindowYears(): int
    {
        return match ($this) {
            self::Short => 1,
            self::Medium => 2,
            self::Long, self::VeryLong => 3,
        };
    }
}
