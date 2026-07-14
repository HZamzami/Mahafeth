<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PriceHistory;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\GoalForecaster;
use App\Services\Analytics\InvestmentPlanBuilder;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs the real analytics pipeline against pathological portfolios (cash
 * only, a single holding, zero prices) and asserts it neither throws nor
 * produces NaN/INF metrics that would break JSON snapshot storage.
 */
class DegeneratePortfolioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{symbol: string, class: AssetClass, prices: list<float>, quantity: float}>  $positions
     */
    private function userWithHoldings(array $positions): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $connection = Connection::factory()->create([
            'user_id' => $user->id,
            'institution_id' => Institution::factory()->create()->id,
        ]);
        $account = Account::factory()->create(['connection_id' => $connection->id]);

        foreach ($positions as $position) {
            $asset = Asset::factory()->create([
                'symbol' => $position['symbol'],
                'asset_class' => $position['class'],
                'currency' => 'SAR',
            ]);

            $account->holdings()->create([
                'asset_id' => $asset->id,
                'quantity' => $position['quantity'],
                'avg_cost' => 1.0,
            ]);

            foreach ($position['prices'] as $offset => $close) {
                PriceHistory::factory()->create([
                    'asset_id' => $asset->id,
                    'date' => now()->subDays(count($position['prices']) - $offset)->toDateString(),
                    'close' => $close,
                ]);
            }
        }

        return $user;
    }

    private function assertMetricsAreJsonSafe(?array $metrics): void
    {
        $this->assertNotNull($metrics);
        $this->assertNotFalse(json_encode($metrics), 'Snapshot metrics contain NaN or INF.');
    }

    public function test_a_cash_only_portfolio_analyzes_with_zero_volatility(): void
    {
        $user = $this->userWithHoldings([
            ['symbol' => 'CASH-SAR', 'class' => AssetClass::Cash, 'prices' => array_fill(0, 10, 1.0), 'quantity' => 5000.0],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        $this->assertNotNull($snapshot);
        $this->assertMetricsAreJsonSafe($snapshot->metrics);
        $this->assertEqualsWithDelta(0.0, $snapshot->metrics['volatility'], 1e-9);
        $this->assertEqualsWithDelta(5000.0, (float) $snapshot->total_value, 0.01);
        $this->assertSame('CASH-SAR', $snapshot->metrics['largest_position']['symbol']);
        $this->assertNotNull($snapshot->health_score);
    }

    public function test_a_single_holding_with_two_price_points_analyzes(): void
    {
        $user = $this->userWithHoldings([
            ['symbol' => 'ONLY', 'class' => AssetClass::Equity, 'prices' => [100.0, 101.0], 'quantity' => 10.0],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        $this->assertNotNull($snapshot);
        $this->assertMetricsAreJsonSafe($snapshot->metrics);
        $this->assertEqualsWithDelta(1.0, array_sum($snapshot->metrics['weights']), 1e-6);
        $this->assertNotNull($snapshot->health_score);
    }

    public function test_a_worthless_portfolio_analyzes_without_nan_metrics(): void
    {
        $user = $this->userWithHoldings([
            ['symbol' => 'ZERO', 'class' => AssetClass::Equity, 'prices' => array_fill(0, 5, 0.0), 'quantity' => 10.0],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        if ($snapshot !== null) {
            $this->assertMetricsAreJsonSafe($snapshot->metrics);
        }

        $this->addToAssertionCount(1);
    }

    public function test_a_mixed_portfolio_with_one_dead_symbol_analyzes(): void
    {
        $user = $this->userWithHoldings([
            ['symbol' => 'LIVE', 'class' => AssetClass::Equity, 'prices' => [100.0, 102.0, 101.0, 103.0, 104.0], 'quantity' => 10.0],
            ['symbol' => 'DEAD', 'class' => AssetClass::Equity, 'prices' => array_fill(0, 5, 0.0), 'quantity' => 10.0],
        ]);

        $snapshot = app(PortfolioAnalyzer::class)->analyze($user);

        $this->assertNotNull($snapshot);
        $this->assertMetricsAreJsonSafe($snapshot->metrics);
        $this->assertSame('LIVE', $snapshot->metrics['largest_position']['symbol']);
    }

    public function test_the_goal_forecast_handles_zero_volatility_deterministically(): void
    {
        $forecast = app(GoalForecaster::class)->forecast(
            currentValue: 10000.0,
            annualReturn: 0.06,
            annualVolatility: 0.0,
            targetAmount: 12000.0,
            months: 36,
            monthlyContribution: 100.0,
        );

        $this->assertNotFalse(json_encode($forecast));
        $this->assertGreaterThanOrEqual(0.0, $forecast['probability']);
        $this->assertLessThanOrEqual(1.0, $forecast['probability']);
        // With no volatility every path is identical, so the bands collapse.
        $this->assertSame($forecast['final']['p10'], $forecast['final']['p90']);
    }

    public function test_the_goal_forecast_handles_a_zero_month_horizon(): void
    {
        $forecast = app(GoalForecaster::class)->forecast(10000.0, 0.06, 0.15, 5000.0, 0);

        $this->assertSame(1.0, $forecast['probability']);
        $this->assertNotFalse(json_encode($forecast));
    }

    public function test_the_investment_plan_builder_returns_null_without_market_data(): void
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $this->assertNull(app(InvestmentPlanBuilder::class)->build($user, 10000.0));
    }
}
