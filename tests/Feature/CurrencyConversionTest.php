<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Asset;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\Analytics\PortfolioDataAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_usd_prices_are_converted_to_sar_in_the_assembled_series(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $account->connection->update(['user_id' => $user->id]);

        $aramco = Asset::factory()->create(['symbol' => '2222.SR', 'currency' => 'SAR']);
        $apple = Asset::factory()->create(['symbol' => 'AAPL', 'currency' => 'USD']);

        $account->holdings()->create(['asset_id' => $aramco->id, 'quantity' => 100, 'avg_cost' => 30.0]);
        $account->holdings()->create(['asset_id' => $apple->id, 'quantity' => 10, 'avg_cost' => 100.0]);

        PriceHistory::create(['asset_id' => $aramco->id, 'date' => today()->toDateString(), 'close' => 37.50]);
        PriceHistory::create(['asset_id' => $apple->id, 'date' => today()->toDateString(), 'close' => 150.00]);

        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subYear());

        // SAR is the base currency and passes through; $150 at the 3.75 peg is 562.50 SAR.
        $this->assertEqualsWithDelta(37.50, $data['priceSeries']['2222.SR'][today()->toDateString()], 1e-9);
        $this->assertEqualsWithDelta(562.50, $data['priceSeries']['AAPL'][today()->toDateString()], 1e-9);
    }

    public function test_benchmark_series_are_converted_too(): void
    {
        $tasi = Asset::factory()->benchmark()->create(['symbol' => 'TASI', 'currency' => 'SAR']);
        PriceHistory::create(['asset_id' => $tasi->id, 'date' => today()->toDateString(), 'close' => 11250.0]);

        $series = app(PortfolioDataAssembler::class)->benchmarkSeriesFor(['TASI'], now()->subYear());

        $this->assertEqualsWithDelta(11250.0, $series['TASI'][today()->toDateString()], 1e-9);
    }
}
