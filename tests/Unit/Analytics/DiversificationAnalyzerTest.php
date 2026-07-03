<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\DiversificationAnalyzer;
use PHPUnit\Framework\TestCase;

class DiversificationAnalyzerTest extends TestCase
{
    private const DELTA = 1e-9;

    private DiversificationAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new DiversificationAnalyzer;
    }

    public function test_hhi_is_the_sum_of_squared_weights(): void
    {
        // 0.25 + 0.09 + 0.04 = 0.38
        $this->assertEqualsWithDelta(0.38, $this->analyzer->hhi(['A' => 0.5, 'B' => 0.3, 'C' => 0.2]), self::DELTA);
    }

    public function test_effective_holdings_is_the_reciprocal_of_hhi(): void
    {
        $this->assertEqualsWithDelta(1 / 0.38, $this->analyzer->effectiveHoldings(['A' => 0.5, 'B' => 0.3, 'C' => 0.2]), self::DELTA);
    }

    public function test_equal_weights_have_effective_holdings_equal_to_the_count(): void
    {
        $this->assertEqualsWithDelta(4.0, $this->analyzer->effectiveHoldings(['A' => 0.25, 'B' => 0.25, 'C' => 0.25, 'D' => 0.25]), self::DELTA);
    }

    public function test_diversification_ratio_matches_the_hand_computed_value(): void
    {
        // (0.6×0.2 + 0.4×0.3) / 0.18 = 0.24 / 0.18 = 4/3
        $ratio = $this->analyzer->diversificationRatio(
            ['A' => 0.6, 'B' => 0.4],
            ['A' => 0.2, 'B' => 0.3],
            0.18,
        );

        $this->assertEqualsWithDelta(4 / 3, $ratio, self::DELTA);
    }

    public function test_largest_position_is_the_maximum_weight(): void
    {
        $this->assertSame(0.6, $this->analyzer->largestPosition(['A' => 0.6, 'B' => 0.4]));
        $this->assertSame(0.0, $this->analyzer->largestPosition([]));
    }

    public function test_group_weights_aggregate_and_sort_descending(): void
    {
        $grouped = $this->analyzer->groupWeights(
            ['A' => 0.5, 'B' => 0.3, 'C' => 0.2],
            ['A' => 'Technology', 'B' => 'Technology', 'C' => 'Energy'],
        );

        $this->assertSame(['Technology', 'Energy'], array_keys($grouped));
        $this->assertEqualsWithDelta(0.8, $grouped['Technology'], self::DELTA);
        $this->assertEqualsWithDelta(0.2, $grouped['Energy'], self::DELTA);
    }

    public function test_symbols_without_a_group_fall_into_other(): void
    {
        $grouped = $this->analyzer->groupWeights(['A' => 1.0], []);

        $this->assertEqualsWithDelta(1.0, $grouped['Other'], self::DELTA);
    }
}
