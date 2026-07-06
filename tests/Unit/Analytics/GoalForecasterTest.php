<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\GoalForecaster;
use Tests\TestCase;

class GoalForecasterTest extends TestCase
{
    private GoalForecaster $forecaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forecaster = new GoalForecaster;
    }

    public function test_the_forecast_is_deterministic(): void
    {
        $first = $this->forecaster->forecast(100_000, 0.08, 0.15, 200_000, 60);
        $second = $this->forecaster->forecast(100_000, 0.08, 0.15, 200_000, 60);

        $this->assertSame($first, $second);
    }

    public function test_a_higher_target_lowers_the_probability(): void
    {
        $modest = $this->forecaster->forecast(100_000, 0.08, 0.15, 120_000, 60);
        $ambitious = $this->forecaster->forecast(100_000, 0.08, 0.15, 500_000, 60);

        $this->assertGreaterThan($ambitious['probability'], $modest['probability']);
    }

    public function test_contributions_raise_the_probability(): void
    {
        $without = $this->forecaster->forecast(100_000, 0.06, 0.15, 250_000, 60);
        $with = $this->forecaster->forecast(100_000, 0.06, 0.15, 250_000, 60, monthlyContribution: 2_000);

        $this->assertGreaterThan($without['probability'], $with['probability']);
    }

    public function test_zero_volatility_compounds_deterministically(): void
    {
        $result = $this->forecaster->forecast(100_000, 0.12, 0.0, 110_000, 12);

        // 12% annual drift with no noise clears a 10% target with certainty.
        $this->assertSame(1.0, $result['probability']);
        $this->assertEqualsWithDelta($result['final']['p10'], $result['final']['p90'], 0.01);
    }

    public function test_bands_are_ordered_and_span_the_horizon(): void
    {
        $result = $this->forecaster->forecast(100_000, 0.08, 0.20, 200_000, 24);

        $this->assertCount(24, $result['bands']['p50']);

        foreach (range(0, 23) as $month) {
            $this->assertLessThanOrEqual($result['bands']['p50'][$month], $result['bands']['p10'][$month]);
            $this->assertLessThanOrEqual($result['bands']['p90'][$month], $result['bands']['p50'][$month]);
        }
    }

    public function test_a_past_target_date_compares_current_value_to_target(): void
    {
        $reached = $this->forecaster->forecast(300_000, 0.08, 0.15, 200_000, 0);
        $missed = $this->forecaster->forecast(100_000, 0.08, 0.15, 200_000, 0);

        $this->assertSame(1.0, $reached['probability']);
        $this->assertSame(0.0, $missed['probability']);
    }
}
