<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\RiskAlignmentScorer;
use PHPUnit\Framework\TestCase;

class RiskAlignmentScorerTest extends TestCase
{
    private const DELTA = 1e-9;

    private RiskAlignmentScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new RiskAlignmentScorer;
    }

    public function test_a_perfect_match_scores_one_hundred(): void
    {
        $this->assertEqualsWithDelta(100.0, $this->scorer->score(0.15, 0.15), self::DELTA);
    }

    public function test_twice_the_target_scores_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->scorer->score(0.30, 0.15), self::DELTA);
    }

    public function test_halfway_off_scores_fifty(): void
    {
        $this->assertEqualsWithDelta(50.0, $this->scorer->score(0.225, 0.15), self::DELTA);
    }

    public function test_being_below_target_is_penalized_symmetrically(): void
    {
        $this->assertEqualsWithDelta(50.0, $this->scorer->score(0.075, 0.15), self::DELTA);
    }

    public function test_far_beyond_double_the_target_clamps_at_zero(): void
    {
        $this->assertSame(0.0, $this->scorer->score(0.60, 0.15));
    }

    public function test_a_zero_target_scores_zero(): void
    {
        $this->assertSame(0.0, $this->scorer->score(0.15, 0.0));
    }
}
