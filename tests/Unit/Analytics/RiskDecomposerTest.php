<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\RiskDecomposer;
use PHPUnit\Framework\TestCase;

class RiskDecomposerTest extends TestCase
{
    private const DELTA = 1e-9;

    private RiskDecomposer $decomposer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decomposer = new RiskDecomposer;
    }

    public function test_a_market_tracking_portfolio_is_fully_systematic(): void
    {
        // β = 1 and portfolio variance equal to the market's.
        $split = $this->decomposer->systematicSplit(beta: 1.0, benchmarkVariance: 0.04, portfolioVariance: 0.04);

        $this->assertEqualsWithDelta(1.0, $split['systematic_share'], self::DELTA);
        $this->assertEqualsWithDelta(0.0, $split['unsystematic_share'], self::DELTA);
    }

    public function test_the_split_matches_the_beta_squared_formula(): void
    {
        // Systematic = 1.5² × 0.02 = 0.045 of a 0.09 total → 50%.
        $split = $this->decomposer->systematicSplit(beta: 1.5, benchmarkVariance: 0.02, portfolioVariance: 0.09);

        $this->assertEqualsWithDelta(0.5, $split['systematic_share'], self::DELTA);
        $this->assertEqualsWithDelta(0.5, $split['unsystematic_share'], self::DELTA);
    }

    public function test_systematic_share_is_capped_at_one(): void
    {
        $split = $this->decomposer->systematicSplit(beta: 3.0, benchmarkVariance: 0.05, portfolioVariance: 0.04);

        $this->assertEqualsWithDelta(1.0, $split['systematic_share'], self::DELTA);
    }

    public function test_equal_uncorrelated_assets_contribute_equally(): void
    {
        $contributions = $this->decomposer->contributions(
            ['A' => 0.5, 'B' => 0.5],
            [
                'A' => ['A' => 0.04, 'B' => 0.0],
                'B' => ['A' => 0.0, 'B' => 0.04],
            ],
            ['A' => 'Tech', 'B' => 'Energy'],
        );

        $this->assertEqualsWithDelta(0.5, $contributions['Tech'], self::DELTA);
        $this->assertEqualsWithDelta(0.5, $contributions['Energy'], self::DELTA);
    }

    public function test_contributions_sum_to_one_and_group_correctly(): void
    {
        $contributions = $this->decomposer->contributions(
            ['A' => 0.5, 'B' => 0.3, 'C' => 0.2],
            [
                'A' => ['A' => 0.08, 'B' => 0.02, 'C' => 0.01],
                'B' => ['A' => 0.02, 'B' => 0.05, 'C' => 0.015],
                'C' => ['A' => 0.01, 'B' => 0.015, 'C' => 0.03],
            ],
            ['A' => 'Tech', 'B' => 'Tech', 'C' => 'Energy'],
        );

        $this->assertEqualsWithDelta(1.0, array_sum($contributions), self::DELTA);
        $this->assertCount(2, $contributions);
        $this->assertGreaterThan($contributions['Energy'], $contributions['Tech']);
    }
}
