<?php

namespace App\Enums;

enum RiskTolerance: string
{
    case VeryConservative = 'very_conservative';
    case Conservative = 'conservative';
    case Balanced = 'balanced';
    case Growth = 'growth';
    case Aggressive = 'aggressive';

    /**
     * Map a questionnaire score (7–28, from the seven scored questions) to a tolerance band.
     */
    public static function fromQuestionnaireScore(int $score): self
    {
        return match (true) {
            $score <= 11 => self::VeryConservative,
            $score <= 15 => self::Conservative,
            $score <= 19 => self::Balanced,
            $score <= 23 => self::Growth,
            default => self::Aggressive,
        };
    }

    /**
     * Annualized portfolio volatility appropriate for this band.
     */
    public function targetVolatility(): float
    {
        return match ($this) {
            self::VeryConservative => 0.06,
            self::Conservative => 0.10,
            self::Balanced => 0.15,
            self::Growth => 0.20,
            self::Aggressive => 0.28,
        };
    }

    /**
     * Annualized return objective for this band.
     */
    public function targetReturn(): float
    {
        return match ($this) {
            self::VeryConservative => 0.04,
            self::Conservative => 0.06,
            self::Balanced => 0.08,
            self::Growth => 0.10,
            self::Aggressive => 0.14,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::VeryConservative => __('Very Conservative'),
            self::Conservative => __('Conservative'),
            self::Balanced => __('Balanced'),
            self::Growth => __('Growth'),
            self::Aggressive => __('Aggressive'),
        };
    }
}
