<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\HealthScoreCalculator;
use App\Services\Analytics\RiskAlignmentScorer;
use Tests\TestCase;

class HealthScoreCalculatorTest extends TestCase
{
    private HealthScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new HealthScoreCalculator(new RiskAlignmentScorer);
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(array $overrides = []): array
    {
        return array_merge([
            'effective_holdings' => 8.0,
            'diversification_ratio' => 1.5,
            'average_correlation' => 0.0,
            'volatility' => 0.15,
            'sharpe' => 1.5,
            'max_drawdown' => 0.05,
            'largest_position' => ['weight' => 0.05],
        ], $overrides);
    }

    public function test_an_ideal_portfolio_scores_one_hundred(): void
    {
        $result = $this->calculator->calculate($this->metrics(), targetVolatility: 0.15);

        $this->assertSame(100, $result['overall']);
        $this->assertSame(
            ['diversification' => 100, 'correlation' => 100, 'risk_alignment' => 100, 'performance' => 100, 'drawdown' => 100, 'concentration' => 100],
            $result['components'],
        );
    }

    public function test_the_worst_portfolio_scores_zero(): void
    {
        $result = $this->calculator->calculate($this->metrics([
            'effective_holdings' => 1.0,
            'diversification_ratio' => 1.0,
            'average_correlation' => 0.9,
            'volatility' => 0.60,
            'sharpe' => -1.0,
            'max_drawdown' => 0.50,
            'largest_position' => ['weight' => 0.60],
        ]), targetVolatility: 0.15);

        $this->assertSame(0, $result['overall']);
    }

    public function test_the_overall_score_is_the_weighted_component_average(): void
    {
        // Only concentration (weight 0.10) drops to 0; everything else stays 100.
        $result = $this->calculator->calculate(
            $this->metrics(['largest_position' => ['weight' => 0.40]]),
            targetVolatility: 0.15,
        );

        $this->assertSame(0, $result['components']['concentration']);
        $this->assertSame(90, $result['overall']);
    }

    public function test_component_curves_interpolate_linearly(): void
    {
        // ENB 4.5 → (4.5−1)/7 = 50; DR 1.25 → 50 → diversification = 50.
        // Correlation 0.35 → 50. Sharpe 0.5 → 50. MDD 0.225 → 50. Largest 0.225 → 50.
        // Volatility 0.225 vs target 0.15 → alignment 50. All components 50 → overall 50.
        $result = $this->calculator->calculate($this->metrics([
            'effective_holdings' => 4.5,
            'diversification_ratio' => 1.25,
            'average_correlation' => 0.35,
            'volatility' => 0.225,
            'sharpe' => 0.5,
            'max_drawdown' => 0.225,
            'largest_position' => ['weight' => 0.225],
        ]), targetVolatility: 0.15);

        $this->assertSame(50, $result['overall']);
    }
}
