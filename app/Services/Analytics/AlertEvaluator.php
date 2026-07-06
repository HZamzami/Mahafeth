<?php

namespace App\Services\Analytics;

use App\Models\RiskProfile;
use Illuminate\Support\Number;

/**
 * Threshold alerts derived from a snapshot's metrics. Returns translation
 * keys with pre-formatted params so the dashboard and notification mails
 * can render the same alerts in their own locales.
 */
class AlertEvaluator
{
    public const CONCENTRATION_THRESHOLD = 0.30;

    public const VOLATILITY_OVERSHOOT = 1.33;

    public const STRESS_CORRELATION_THRESHOLD = 0.50;

    /**
     * @param  ?array<string, mixed>  $metrics  a portfolio snapshot's metrics payload
     * @return list<array{key: string, color: string, params: array<string, string>}>
     */
    public function evaluate(?array $metrics, ?RiskProfile $riskProfile): array
    {
        if ($metrics === null) {
            return [];
        }

        $alerts = [];

        $largest = $metrics['largest_position'] ?? null;
        if ($largest !== null && $largest['weight'] > self::CONCENTRATION_THRESHOLD) {
            $alerts[] = [
                'key' => 'Concentration alert: :name is :weight of your portfolio — above the :threshold threshold.',
                'color' => 'red',
                'params' => [
                    'name' => $largest['name'],
                    'weight' => Number::percentage($largest['weight'] * 100, 1),
                    'threshold' => Number::percentage(self::CONCENTRATION_THRESHOLD * 100),
                ],
            ];
        }

        $target = $riskProfile?->target_volatility;
        if ($target !== null && ($metrics['volatility'] ?? 0) > $target * self::VOLATILITY_OVERSHOOT) {
            $alerts[] = [
                'key' => 'Risk alert: portfolio volatility of :volatility is well above your :target target.',
                'color' => 'amber',
                'params' => [
                    'volatility' => Number::percentage($metrics['volatility'] * 100, 1),
                    'target' => Number::percentage($target * 100, 1),
                ],
            ];
        }

        if (($metrics['stress_correlation'] ?? 0) > self::STRESS_CORRELATION_THRESHOLD) {
            $alerts[] = [
                'key' => 'Correlation alert: in a market crisis your assets would move together with an estimated correlation of :correlation.',
                'color' => 'amber',
                'params' => [
                    'correlation' => number_format($metrics['stress_correlation'], 2),
                ],
            ];
        }

        return $alerts;
    }
}
