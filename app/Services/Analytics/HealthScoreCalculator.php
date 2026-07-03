<?php

namespace App\Services\Analytics;

/**
 * Composite Portfolio Health Score (0–100): a weighted average of six
 * component scores, each normalized to 0–100 with a documented linear curve.
 * Component weights live in config('mahafeth.health_weights').
 */
class HealthScoreCalculator
{
    public function __construct(private RiskAlignmentScorer $riskAlignmentScorer) {}

    /**
     * Compute all component scores plus the weighted overall score.
     *
     * @param  array<string, mixed>  $metrics  a portfolio snapshot's metrics payload
     * @return array{components: array<string, int>, overall: int}
     */
    public function calculate(array $metrics, float $targetVolatility): array
    {
        $components = [
            'diversification' => $this->diversificationScore(
                (float) $metrics['effective_holdings'],
                (float) $metrics['diversification_ratio'],
            ),
            'correlation' => $this->correlationScore(
                (float) $metrics['average_correlation'],
                isset($metrics['pca_first_factor_share']) ? (float) $metrics['pca_first_factor_share'] : null,
            ),
            'risk_alignment' => $this->riskAlignmentScorer->score((float) $metrics['volatility'], $targetVolatility),
            'performance' => $this->performanceScore((float) $metrics['sharpe']),
            'drawdown' => $this->drawdownScore((float) $metrics['max_drawdown']),
            'concentration' => $this->concentrationScore((float) $metrics['largest_position']['weight']),
        ];

        $weights = config('mahafeth.health_weights');
        $overall = 0.0;

        foreach ($components as $name => $score) {
            $overall += ($weights[$name] ?? 0) * $score;
        }

        return [
            'components' => array_map(fn (float $score): int => (int) round($score), $components),
            'overall' => (int) round($overall),
        ];
    }

    /**
     * 60% effective holdings (1 → 0, 8+ → 100), 40% diversification ratio
     * (1 → 0, 1.5+ → 100). Both linear.
     */
    private function diversificationScore(float $effectiveHoldings, float $diversificationRatio): float
    {
        $enbScore = $this->linear($effectiveHoldings, 1.0, 8.0);
        $ratioScore = $this->linear($diversificationRatio, 1.0, 1.5);

        return 0.6 * $enbScore + 0.4 * $ratioScore;
    }

    /**
     * 70% average pairwise correlation (0 → 100, 0.7+ → 0) and 30% PCA
     * hidden-factor share (40% or less of variance in one factor → 100,
     * 90%+ → 0). Older snapshots without the PCA metric fall back to the
     * average alone.
     */
    private function correlationScore(float $averageCorrelation, ?float $firstFactorShare): float
    {
        $averageScore = 100 - $this->linear($averageCorrelation, 0.0, 0.7);

        if ($firstFactorShare === null) {
            return $averageScore;
        }

        $pcaScore = 100 - $this->linear($firstFactorShare, 0.40, 0.90);

        return 0.7 * $averageScore + 0.3 * $pcaScore;
    }

    /**
     * Sharpe ratio: −0.5 → 0, 1.5+ → 100.
     */
    private function performanceScore(float $sharpe): float
    {
        return $this->linear($sharpe, -0.5, 1.5);
    }

    /**
     * Max drawdown: 5% or less → 100, 40%+ → 0.
     */
    private function drawdownScore(float $maxDrawdown): float
    {
        return 100 - $this->linear($maxDrawdown, 0.05, 0.40);
    }

    /**
     * Largest position weight: 5% or less → 100, 40%+ → 0.
     */
    private function concentrationScore(float $largestPosition): float
    {
        return 100 - $this->linear($largestPosition, 0.05, 0.40);
    }

    /**
     * Linear ramp from 0 at $floor to 100 at $ceiling, clamped.
     */
    private function linear(float $value, float $floor, float $ceiling): float
    {
        return 100 * min(1.0, max(0.0, ($value - $floor) / ($ceiling - $floor)));
    }
}
