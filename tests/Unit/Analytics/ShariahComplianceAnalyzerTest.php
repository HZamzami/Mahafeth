<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\ShariahComplianceAnalyzer;
use Tests\TestCase;

class ShariahComplianceAnalyzerTest extends TestCase
{
    private ShariahComplianceAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new ShariahComplianceAnalyzer;
    }

    public function test_weights_are_bucketed_by_shariah_status(): void
    {
        $result = $this->analyzer->analyze(
            ['2222.SR' => 0.5, 'JPM' => 0.3, 'BTC' => 0.2],
            [
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
                'JPM' => ['name' => 'JPMorgan Chase & Co.', 'shariah_status' => 'non_compliant'],
                'BTC' => ['name' => 'Bitcoin', 'shariah_status' => 'unknown'],
            ],
        );

        $this->assertEqualsWithDelta(0.5, $result['compliant_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $result['non_compliant_weight'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $result['unknown_weight'], 1e-9);
    }

    public function test_non_compliant_positions_are_listed_largest_first(): void
    {
        $result = $this->analyzer->analyze(
            ['JPM' => 0.1, '1010.SR' => 0.4],
            [
                'JPM' => ['name' => 'JPMorgan Chase & Co.', 'shariah_status' => 'non_compliant'],
                '1010.SR' => ['name' => 'Riyad Bank', 'shariah_status' => 'non_compliant'],
            ],
        );

        $this->assertSame(['1010.SR', 'JPM'], array_column($result['non_compliant_positions'], 'symbol'));
        $this->assertSame('Riyad Bank', $result['non_compliant_positions'][0]['name']);
    }

    public function test_assets_without_a_status_count_as_unknown(): void
    {
        $result = $this->analyzer->analyze(
            ['XYZ' => 1.0],
            ['XYZ' => ['name' => 'Mystery Asset']],
        );

        $this->assertEqualsWithDelta(1.0, $result['unknown_weight'], 1e-9);
        $this->assertSame([], $result['non_compliant_positions']);
    }

    public function test_a_fully_compliant_portfolio_has_no_findings(): void
    {
        $result = $this->analyzer->analyze(
            ['2222.SR' => 0.6, '1120.SR' => 0.4],
            [
                '2222.SR' => ['name' => 'Saudi Aramco', 'shariah_status' => 'compliant'],
                '1120.SR' => ['name' => 'Al Rajhi Bank', 'shariah_status' => 'compliant'],
            ],
        );

        $this->assertEqualsWithDelta(1.0, $result['compliant_weight'], 1e-9);
        $this->assertSame([], $result['non_compliant_positions']);
    }
}
