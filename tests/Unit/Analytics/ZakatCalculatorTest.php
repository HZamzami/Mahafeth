<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\ZakatCalculator;
use Tests\TestCase;

class ZakatCalculatorTest extends TestCase
{
    private ZakatCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ZakatCalculator;
    }

    public function test_zakat_is_the_configured_rate_of_zakatable_wealth(): void
    {
        config(['mahafeth.zakat' => ['rate' => 0.025, 'nisab' => 35000.0]]);

        $result = $this->calculator->calculate(
            ['AAPL' => 60000.0, 'CASH-SAR' => 40000.0],
            [
                'AAPL' => ['asset_class' => 'equity'],
                'CASH-SAR' => ['asset_class' => 'cash'],
            ],
        );

        $this->assertSame(100000.0, $result['zakatable_value']);
        $this->assertSame(2500.0, $result['zakat_due']);
        $this->assertFalse($result['below_nisab']);
    }

    public function test_no_zakat_is_due_below_the_nisab_threshold(): void
    {
        config(['mahafeth.zakat' => ['rate' => 0.025, 'nisab' => 35000.0]]);

        $result = $this->calculator->calculate(
            ['CASH-SAR' => 20000.0],
            ['CASH-SAR' => ['asset_class' => 'cash']],
        );

        $this->assertSame(20000.0, $result['zakatable_value']);
        $this->assertSame(0.0, $result['zakat_due']);
        $this->assertTrue($result['below_nisab']);
    }

    public function test_non_zakatable_asset_classes_are_excluded(): void
    {
        config(['mahafeth.zakat' => ['rate' => 0.025, 'nisab' => 35000.0]]);

        $result = $this->calculator->calculate(
            ['REIT' => 500000.0, 'SUKUK' => 100000.0, 'BTC' => 40000.0],
            [
                'REIT' => ['asset_class' => 'real_estate'],
                'SUKUK' => ['asset_class' => 'bond'],
                'BTC' => ['asset_class' => 'crypto'],
            ],
        );

        $this->assertSame(40000.0, $result['zakatable_value']);
        $this->assertSame(1000.0, $result['zakat_due']);
    }
}
