<?php

namespace App\Services\Analytics;

use App\Models\AlertRule;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
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
     * Built-in alerts plus the user's own alert rules, sharing one shape
     * so the dashboard, dismissal, and notifications treat them alike.
     * The identity field is stable across value changes; the fingerprint
     * changes with the displayed values (dismissals key off it).
     *
     * @return list<array{key: string, color: string, identity: string, fingerprint: string, params: array<string, string>}>
     */
    public function forUser(User $user, ?PortfolioSnapshot $snapshot): array
    {
        return [
            ...$this->evaluate($snapshot?->metrics, $user->riskProfile),
            ...$this->customAlerts($user, $snapshot),
        ];
    }

    /**
     * @param  ?array<string, mixed>  $metrics  a portfolio snapshot's metrics payload
     * @return list<array{key: string, color: string, identity: string, fingerprint: string, params: array<string, string>}>
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
                'identity' => 'concentration',
                'fingerprint' => $this->fingerprint('concentration', $largest['name'], round($largest['weight'], 3)),
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
                'identity' => 'volatility',
                'fingerprint' => $this->fingerprint('volatility', round($metrics['volatility'], 3), round($target, 3)),
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
                'identity' => 'correlation',
                'fingerprint' => $this->fingerprint('correlation', round($metrics['stress_correlation'], 2)),
                'params' => [
                    'correlation' => number_format($metrics['stress_correlation'], 2),
                ],
            ];
        }

        return $alerts;
    }

    /**
     * The user's own alert rules, evaluated against the snapshot. Ratio
     * metrics compare against the metrics payload; the health score reads
     * the snapshot column.
     *
     * @return list<array{key: string, color: string, identity: string, fingerprint: string, params: array<string, string>}>
     */
    private function customAlerts(User $user, ?PortfolioSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $alerts = [];

        foreach ($user->alertRules()->where('enabled', true)->get() as $rule) {
            $definition = AlertRule::METRICS[$rule->metric] ?? null;
            $value = $this->metricValue($rule->metric, $snapshot);

            if ($definition === null || $value === null) {
                continue;
            }

            $crossed = $definition['direction'] === 'above'
                ? $value > $rule->threshold
                : $value < $rule->threshold;

            if (! $crossed) {
                continue;
            }

            $format = fn (float $number): string => $definition['unit'] === 'percent'
                ? Number::percentage($number * 100, 1)
                : (string) round($number);

            $alerts[] = [
                'key' => $definition['key'],
                'color' => 'amber',
                'identity' => 'custom:'.$rule->id,
                'fingerprint' => $this->fingerprint('custom:'.$rule->id, round($value, 3), round($rule->threshold, 3)),
                'params' => [
                    'value' => $format($value),
                    'threshold' => $format($rule->threshold),
                ],
            ];
        }

        return $alerts;
    }

    private function metricValue(string $metric, PortfolioSnapshot $snapshot): ?float
    {
        $metrics = $snapshot->metrics ?? [];

        return match ($metric) {
            'volatility' => isset($metrics['volatility']) ? (float) $metrics['volatility'] : null,
            'largest_position' => isset($metrics['largest_position']['weight']) ? (float) $metrics['largest_position']['weight'] : null,
            'max_drawdown' => isset($metrics['max_drawdown']) ? (float) $metrics['max_drawdown'] : null,
            'health_score' => $snapshot->health_score !== null ? (float) $snapshot->health_score : null,
            default => null,
        };
    }

    /**
     * Locale-independent identity for a dismissed alert, built from the
     * alert type and its raw values rounded to display precision: the
     * alert only resurfaces once the metric moves enough to change what
     * the user would actually read.
     */
    private function fingerprint(string $type, string|float ...$values): string
    {
        return md5($type.'|'.implode('|', $values));
    }
}
