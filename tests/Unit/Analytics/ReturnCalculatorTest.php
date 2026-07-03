<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\ReturnCalculator;
use PHPUnit\Framework\TestCase;

class ReturnCalculatorTest extends TestCase
{
    private const DELTA = 1e-9;

    private ReturnCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ReturnCalculator;
    }

    public function test_simple_returns_measure_percentage_price_change(): void
    {
        $returns = $this->calculator->simpleReturns([
            '2026-01-01' => 100.0,
            '2026-01-02' => 110.0,
            '2026-01-03' => 99.0,
        ]);

        $this->assertSame(['2026-01-02', '2026-01-03'], array_keys($returns));
        $this->assertEqualsWithDelta(0.10, $returns['2026-01-02'], self::DELTA);
        $this->assertEqualsWithDelta(-0.10, $returns['2026-01-03'], self::DELTA);
    }

    public function test_log_returns_are_the_log_of_the_price_ratio(): void
    {
        $returns = $this->calculator->logReturns([
            '2026-01-01' => 100.0,
            '2026-01-02' => 110.0,
            '2026-01-03' => 99.0,
        ]);

        $this->assertEqualsWithDelta(log(1.1), $returns['2026-01-02'], self::DELTA);
        $this->assertEqualsWithDelta(log(0.9), $returns['2026-01-03'], self::DELTA);
    }

    public function test_aligned_log_returns_are_restricted_to_shared_dates(): void
    {
        $aligned = $this->calculator->alignedLogReturns([
            'A' => ['2026-01-01' => 100.0, '2026-01-02' => 110.0, '2026-01-03' => 121.0],
            'B' => ['2026-01-02' => 50.0, '2026-01-03' => 55.0, '2026-01-04' => 60.0],
        ]);

        // Shared dates are Jan 2 and Jan 3, yielding one return per asset.
        $this->assertCount(1, $aligned['A']);
        $this->assertCount(1, $aligned['B']);
        $this->assertEqualsWithDelta(log(1.1), $aligned['A'][0], self::DELTA);
        $this->assertEqualsWithDelta(log(1.1), $aligned['B'][0], self::DELTA);
    }

    public function test_portfolio_value_series_sums_quantity_times_close_over_shared_dates(): void
    {
        $values = $this->calculator->portfolioValueSeries(
            [
                'A' => ['2026-01-01' => 100.0, '2026-01-02' => 110.0, '2026-01-03' => 121.0],
                'B' => ['2026-01-02' => 50.0, '2026-01-03' => 55.0],
            ],
            ['A' => 2.0, 'B' => 3.0],
        );

        $this->assertSame(['2026-01-02', '2026-01-03'], array_keys($values));
        $this->assertEqualsWithDelta(370.0, $values['2026-01-02'], self::DELTA);
        $this->assertEqualsWithDelta(407.0, $values['2026-01-03'], self::DELTA);
    }

    public function test_expected_return_is_the_weighted_average_of_asset_returns(): void
    {
        $expected = $this->calculator->expectedReturn(
            ['A' => 0.6, 'B' => 0.4],
            ['A' => 0.10, 'B' => 0.05],
        );

        $this->assertEqualsWithDelta(0.08, $expected, self::DELTA);
    }

    public function test_annualized_return_scales_the_mean_daily_return_by_trading_days(): void
    {
        $annualized = $this->calculator->annualizedReturn([0.001, 0.002, 0.003]);

        $this->assertEqualsWithDelta(0.002 * 252, $annualized, self::DELTA);
    }

    public function test_annualized_return_of_an_empty_series_is_zero(): void
    {
        $this->assertSame(0.0, $this->calculator->annualizedReturn([]));
    }
}
