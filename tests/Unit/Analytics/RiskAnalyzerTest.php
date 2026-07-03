<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\CovarianceMatrixService;
use App\Services\Analytics\RiskAnalyzer;
use PHPUnit\Framework\TestCase;

class RiskAnalyzerTest extends TestCase
{
    private const DELTA = 1e-9;

    private RiskAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new RiskAnalyzer(new CovarianceMatrixService);
    }

    public function test_portfolio_variance_matches_the_hand_computed_markowitz_value(): void
    {
        // w = [0.6, 0.4], Σ = [[0.04, 0.01], [0.01, 0.09]]
        // σ²p = 0.36·0.04 + 2·0.24·0.01 + 0.16·0.09 = 0.0336
        $weights = ['A' => 0.6, 'B' => 0.4];
        $covariance = [
            'A' => ['A' => 0.04, 'B' => 0.01],
            'B' => ['A' => 0.01, 'B' => 0.09],
        ];

        $this->assertEqualsWithDelta(0.0336, $this->analyzer->portfolioVariance($weights, $covariance), self::DELTA);
        $this->assertEqualsWithDelta(sqrt(0.0336), $this->analyzer->portfolioVolatility($weights, $covariance), self::DELTA);
    }

    public function test_beta_is_two_when_the_portfolio_doubles_every_market_move(): void
    {
        $market = [0.01, -0.02, 0.02];
        $portfolio = [0.02, -0.04, 0.04];

        $this->assertEqualsWithDelta(2.0, $this->analyzer->beta($portfolio, $market), self::DELTA);
    }

    public function test_beta_is_zero_when_the_benchmark_does_not_move(): void
    {
        $this->assertSame(0.0, $this->analyzer->beta([0.01, 0.02], [0.0, 0.0]));
    }

    public function test_value_at_risk_matches_the_parametric_formula(): void
    {
        // VaR = 0.08 − 1.645 × 0.20 = −0.249
        $this->assertEqualsWithDelta(-0.249, $this->analyzer->valueAtRisk(0.08, 0.20, zScore: 1.645), self::DELTA);
    }

    public function test_conditional_value_at_risk_is_worse_than_value_at_risk(): void
    {
        // CVaR = μ − σ·φ(z)/(1−c); φ(1.645) ≈ 0.103103 → ≈ −0.332412
        $cvar = $this->analyzer->conditionalValueAtRisk(0.08, 0.20, zScore: 1.645, confidence: 0.95);

        $this->assertEqualsWithDelta(-0.33241, $cvar, 1e-4);
        $this->assertLessThan($this->analyzer->valueAtRisk(0.08, 0.20, zScore: 1.645), $cvar);
    }

    public function test_max_drawdown_is_the_worst_peak_to_trough_loss(): void
    {
        // Peak 120 → trough 80: (120 − 80) / 120 = 1/3
        $this->assertEqualsWithDelta(1 / 3, $this->analyzer->maxDrawdown([100, 120, 90, 110, 80]), self::DELTA);
    }

    public function test_max_drawdown_is_zero_for_a_rising_series(): void
    {
        $this->assertSame(0.0, $this->analyzer->maxDrawdown([100, 110, 120]));
    }

    public function test_sharpe_ratio_measures_excess_return_per_unit_of_risk(): void
    {
        $this->assertEqualsWithDelta(0.4, $this->analyzer->sharpeRatio(0.10, 0.15, riskFreeRate: 0.04), self::DELTA);
    }

    public function test_sortino_ratio_uses_downside_deviation(): void
    {
        $this->assertEqualsWithDelta(1.2, $this->analyzer->sortinoRatio(0.10, 0.05, riskFreeRate: 0.04), self::DELTA);
    }

    public function test_downside_deviation_penalizes_only_negative_returns(): void
    {
        // Shortfalls: [0, −0.02, 0, −0.01] → √(0.0005/4) × √252
        $expected = sqrt(0.0005 / 4) * sqrt(252);

        $this->assertEqualsWithDelta(
            $expected,
            $this->analyzer->downsideDeviation([0.01, -0.02, 0.005, -0.01]),
            self::DELTA,
        );
    }
}
