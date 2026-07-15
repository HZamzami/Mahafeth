<?php

namespace App\Services\Analytics;

use App\Models\PortfolioSnapshot;
use Illuminate\Support\Number;

/**
 * Explains why the health score moved between two snapshots: which component
 * scores shifted, and the underlying metric behind each shift. Like
 * AlertEvaluator, output carries raw translation keys plus pre-formatted
 * params so it can be rendered later in any locale.
 */
class HealthDeltaExplainer
{
    private const MINIMUM_COMPONENT_DELTA = 2;

    private const COMPONENT_LABELS = [
        'diversification' => 'Diversification',
        'risk_alignment' => 'Risk Alignment',
        'correlation' => 'Correlation',
        'performance' => 'Performance',
        'drawdown' => 'Drawdown',
        'concentration' => 'Concentration',
        'shariah' => 'Shariah Compliance',
    ];

    /**
     * @return list<array{component: string, label: string, delta: int, driver_key: ?string, driver_params: array<string, string>}>
     *                                                                                                                              movers sorted by |delta| descending; empty when nothing shifted
     */
    public function explain(?PortfolioSnapshot $current, ?PortfolioSnapshot $previous): array
    {
        $currentScores = $current?->component_scores;
        $previousScores = $previous?->component_scores;

        if ($currentScores === null || $previousScores === null) {
            return [];
        }

        $movers = [];

        foreach ($currentScores as $component => $score) {
            $delta = (int) $score - (int) ($previousScores[$component] ?? $score);

            if (abs($delta) < self::MINIMUM_COMPONENT_DELTA) {
                continue;
            }

            $driver = $this->driver($component, $current->metrics ?? [], $previous->metrics ?? []);

            $movers[] = [
                'component' => $component,
                'label' => self::COMPONENT_LABELS[$component] ?? $component,
                'delta' => $delta,
                'driver_key' => $driver['key'] ?? null,
                'driver_params' => $driver['params'] ?? [],
            ];
        }

        usort($movers, fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));

        return $movers;
    }

    /**
     * The metric movement behind a component shift.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $before
     * @return array{key: string, params: array<string, string>}|null
     */
    private function driver(string $component, array $now, array $before): ?array
    {
        return match ($component) {
            'diversification' => $this->numericDriver(
                'Effective holdings moved from :from to :to.',
                $before['effective_holdings'] ?? null,
                $now['effective_holdings'] ?? null,
                fn (float $value): string => Number::format($value, 1),
            ),
            'risk_alignment' => $this->numericDriver(
                'Portfolio volatility moved from :from to :to.',
                $before['volatility'] ?? null,
                $now['volatility'] ?? null,
                fn (float $value): string => Number::percentage($value * 100, 1),
            ),
            'correlation' => $this->numericDriver(
                'Average correlation moved from :from to :to.',
                $before['average_correlation'] ?? null,
                $now['average_correlation'] ?? null,
                fn (float $value): string => Number::format($value, 2),
            ),
            'performance' => $this->numericDriver(
                'The Sharpe ratio moved from :from to :to.',
                $before['sharpe'] ?? null,
                $now['sharpe'] ?? null,
                fn (float $value): string => Number::format($value, 2),
            ),
            'drawdown' => $this->numericDriver(
                'Maximum drawdown moved from :from to :to.',
                $before['max_drawdown'] ?? null,
                $now['max_drawdown'] ?? null,
                fn (float $value): string => Number::percentage($value * 100, 1),
            ),
            'concentration' => isset($now['largest_position']['weight']) ? [
                'key' => ':name is now :weight of the portfolio.',
                'params' => [
                    'name' => $now['largest_position']['name'] ?? ($now['largest_position']['symbol'] ?? ''),
                    'weight' => Number::percentage($now['largest_position']['weight'] * 100, 1),
                ],
            ] : null,
            'shariah' => $this->numericDriver(
                'The compliant share of your portfolio moved from :from to :to.',
                $before['shariah']['compliant_weight'] ?? null,
                $now['shariah']['compliant_weight'] ?? null,
                fn (float $value): string => Number::percentage($value * 100, 1),
            ),
            default => null,
        };
    }

    /**
     * @return array{key: string, params: array<string, string>}|null
     */
    private function numericDriver(string $key, ?float $from, ?float $to, callable $format): ?array
    {
        if ($from === null || $to === null) {
            return null;
        }

        return [
            'key' => $key,
            'params' => ['from' => $format($from), 'to' => $format($to)],
        ];
    }
}
