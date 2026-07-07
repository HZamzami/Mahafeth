<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\StressScenarioAnalyzer;
use Tests\TestCase;

class StressScenarioAnalyzerTest extends TestCase
{
    private StressScenarioAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new StressScenarioAnalyzer;
    }

    /**
     * @return array{market: float, targets: list<array{group: string, value: string, shock: float}>}
     */
    private function techCorrection(): array
    {
        return [
            'market' => -0.08,
            'targets' => [
                ['group' => 'sector', 'value' => 'Technology', 'shock' => -0.25],
            ],
        ];
    }

    public function test_targeted_holdings_take_the_harder_shock_and_others_the_market_move(): void
    {
        $result = $this->analyzer->apply(
            ['AAPL' => 0.5, '2222.SR' => 0.5],
            [
                'AAPL' => ['name' => 'Apple Inc.', 'sector' => 'Technology', 'asset_class' => 'equity'],
                '2222.SR' => ['name' => 'Saudi Aramco', 'sector' => 'Energy', 'asset_class' => 'equity'],
            ],
            $this->techCorrection(),
        );

        // 0.5 * -0.25 + 0.5 * -0.08
        $this->assertEqualsWithDelta(-0.165, $result['impact'], 1e-9);
        $this->assertSame('AAPL', $result['positions'][0]['symbol']);
        $this->assertEqualsWithDelta(-0.25, $result['positions'][0]['shock'], 1e-9);
        $this->assertEqualsWithDelta(-0.08, $result['positions'][1]['shock'], 1e-9);
    }

    public function test_asset_class_targets_match_and_positions_sort_by_contribution(): void
    {
        $result = $this->analyzer->apply(
            ['BTC' => 0.2, 'AAPL' => 0.8],
            [
                'BTC' => ['name' => 'Bitcoin', 'sector' => null, 'asset_class' => 'crypto'],
                'AAPL' => ['name' => 'Apple Inc.', 'sector' => 'Technology', 'asset_class' => 'equity'],
            ],
            [
                'market' => -0.03,
                'targets' => [['group' => 'asset_class', 'value' => 'crypto', 'shock' => -0.45]],
            ],
        );

        // BTC contributes -0.09, AAPL -0.024, so BTC leads despite its size.
        $this->assertSame('BTC', $result['positions'][0]['symbol']);
        $this->assertEqualsWithDelta(-0.114, $result['impact'], 1e-9);
    }
}
