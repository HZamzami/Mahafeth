<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\RebalancePlanner;
use Tests\TestCase;

class RebalancePlannerTest extends TestCase
{
    private RebalancePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new RebalancePlanner;
    }

    public function test_weight_gaps_become_orders_with_hand_computed_quantities(): void
    {
        // 100k portfolio: AAPL 60% at 600 SAR, Aramco 40% at 30 SAR.
        // Target: 40/60. AAPL sells 20k (33.3333 units), Aramco buys 20k (666.6667 units).
        $orders = $this->planner->plan(
            currentWeights: ['AAPL' => 0.6, '2222.SR' => 0.4],
            targetWeights: ['AAPL' => 0.4, '2222.SR' => 0.6],
            totalValue: 100_000,
            quantities: ['AAPL' => 100.0, '2222.SR' => 1333.3333333],
            assets: [
                'AAPL' => ['name' => 'Apple Inc.', 'shariah_status' => 'compliant'],
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
            ],
            minTradeValue: 500,
        );

        $this->assertCount(2, $orders);

        $bySymbol = array_column($orders, null, 'symbol');

        $this->assertSame('sell', $bySymbol['AAPL']['side']);
        $this->assertEqualsWithDelta(33.3333, $bySymbol['AAPL']['quantity'], 0.001);
        $this->assertEqualsWithDelta(20_000, $bySymbol['AAPL']['value'], 0.01);

        $this->assertSame('buy', $bySymbol['2222.SR']['side']);
        $this->assertEqualsWithDelta(20_000, $bySymbol['2222.SR']['value'], 0.01);
    }

    public function test_the_plan_is_cash_neutral(): void
    {
        $orders = $this->planner->plan(
            currentWeights: ['A' => 0.5, 'B' => 0.3, 'C' => 0.2],
            targetWeights: ['A' => 0.2, 'B' => 0.5, 'C' => 0.3],
            totalValue: 200_000,
            quantities: ['A' => 100, 'B' => 100, 'C' => 100],
            assets: [
                'A' => ['name' => 'A'], 'B' => ['name' => 'B'], 'C' => ['name' => 'C'],
            ],
            minTradeValue: 0,
        );

        $net = array_sum(array_map(
            fn (array $order): float => ($order['side'] === 'buy' ? 1 : -1) * $order['value'],
            $orders,
        ));

        $this->assertEqualsWithDelta(0.0, $net, 1.0);
    }

    public function test_dust_orders_are_skipped(): void
    {
        $orders = $this->planner->plan(
            currentWeights: ['A' => 0.501, 'B' => 0.499],
            targetWeights: ['A' => 0.5, 'B' => 0.5],
            totalValue: 100_000,
            quantities: ['A' => 100, 'B' => 100],
            assets: ['A' => ['name' => 'A'], 'B' => ['name' => 'B']],
            minTradeValue: 500,
        );

        $this->assertSame([], $orders);
    }

    public function test_shariah_investors_get_no_buys_of_non_compliant_assets(): void
    {
        $orders = $this->planner->plan(
            currentWeights: ['AAPL' => 0.5, 'JPM' => 0.2, '2222.SR' => 0.3],
            targetWeights: ['AAPL' => 0.3, 'JPM' => 0.4, '2222.SR' => 0.3],
            totalValue: 100_000,
            quantities: ['AAPL' => 100, 'JPM' => 100, '2222.SR' => 1000],
            assets: [
                'AAPL' => ['name' => 'Apple Inc.', 'shariah_status' => 'compliant'],
                'JPM' => ['name' => 'JPMorgan Chase & Co.', 'shariah_status' => 'non_compliant'],
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
            ],
            shariahRequired: true,
            minTradeValue: 0,
        );

        $sides = array_column($orders, 'side', 'symbol');

        $this->assertArrayNotHasKey('JPM', $sides);
        $this->assertSame('sell', $sides['AAPL']);
    }

    public function test_the_excluded_buy_budget_is_redistributed_to_compliant_buys(): void
    {
        // JPM's 20k buy is excluded; Aramco's 10k buy absorbs it, keeping
        // the plan cash-neutral against AAPL's 30k sell.
        $orders = $this->planner->plan(
            currentWeights: ['AAPL' => 0.6, 'JPM' => 0.2, '2222.SR' => 0.2],
            targetWeights: ['AAPL' => 0.3, 'JPM' => 0.4, '2222.SR' => 0.3],
            totalValue: 100_000,
            quantities: ['AAPL' => 100, 'JPM' => 100, '2222.SR' => 1000],
            assets: [
                'AAPL' => ['name' => 'Apple Inc.', 'shariah_status' => 'compliant'],
                'JPM' => ['name' => 'JPMorgan Chase & Co.', 'shariah_status' => 'non_compliant'],
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
            ],
            shariahRequired: true,
            minTradeValue: 0,
        );

        $bySymbol = array_column($orders, null, 'symbol');

        $this->assertEqualsWithDelta(30_000, $bySymbol['2222.SR']['value'], 0.01);

        $net = array_sum(array_map(
            fn (array $order): float => ($order['side'] === 'buy' ? 1 : -1) * $order['value'],
            $orders,
        ));

        $this->assertEqualsWithDelta(0.0, $net, 1.0);
    }

    public function test_selling_non_compliant_assets_is_always_allowed(): void
    {
        $orders = $this->planner->plan(
            currentWeights: ['JPM' => 0.4, '2222.SR' => 0.6],
            targetWeights: ['JPM' => 0.1, '2222.SR' => 0.9],
            totalValue: 100_000,
            quantities: ['JPM' => 100, '2222.SR' => 1000],
            assets: [
                'JPM' => ['name' => 'JPMorgan Chase & Co.', 'shariah_status' => 'non_compliant'],
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
            ],
            shariahRequired: true,
            minTradeValue: 0,
        );

        $sides = array_column($orders, 'side', 'symbol');

        $this->assertSame('sell', $sides['JPM']);
    }
}
