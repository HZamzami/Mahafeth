<?php

namespace Tests\Feature;

use App\Contracts\PriceProvider;
use App\Enums\AssetClass;
use App\Models\Asset;
use App\Services\Prices\SimulatedPriceProvider;
use App\Services\Prices\TwelveDataPriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PriceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_simulated_provider_is_bound_without_an_api_key(): void
    {
        config(['services.twelvedata.key' => null]);

        $this->assertInstanceOf(SimulatedPriceProvider::class, app(PriceProvider::class));
    }

    public function test_the_twelve_data_provider_is_bound_with_an_api_key(): void
    {
        config(['services.twelvedata.key' => 'key-123']);

        $this->assertInstanceOf(TwelveDataPriceProvider::class, app(PriceProvider::class));
    }

    public function test_tadawul_symbols_map_to_the_xsau_exchange(): void
    {
        config(['services.twelvedata.key' => 'key-123']);

        Http::fake([
            'api.twelvedata.com/*' => Http::response([
                'status' => 'ok',
                'values' => [
                    ['datetime' => '2026-07-03', 'close' => '27.55'],
                    ['datetime' => '2026-07-02', 'close' => '27.10'],
                ],
            ]),
        ]);

        $series = app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['2222.SR'], now()->subDays(5), now());

        $this->assertSame(['2026-07-02' => 27.10, '2026-07-03' => 27.55], $series['2222.SR']);

        Http::assertSent(function ($request): bool {
            return $request['symbol'] === '2222' && $request['mic_code'] === 'XSAU';
        });
    }

    public function test_crypto_symbols_map_to_usd_pairs(): void
    {
        config(['services.twelvedata.key' => 'key-123']);
        Asset::factory()->create(['symbol' => 'BTC', 'asset_class' => AssetClass::Crypto]);

        Http::fake([
            'api.twelvedata.com/*' => Http::response([
                'status' => 'ok',
                'values' => [['datetime' => '2026-07-03', 'close' => '65000.0']],
            ]),
        ]);

        $series = app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['BTC'], now()->subDays(5), now());

        $this->assertSame(['2026-07-03' => 65000.0], $series['BTC']);

        Http::assertSent(fn ($request): bool => $request['symbol'] === 'BTC/USD');
    }

    public function test_cash_assets_skip_the_api_and_use_the_simulated_series(): void
    {
        config(['services.twelvedata.key' => 'key-123']);
        Asset::factory()->create(['symbol' => 'CASH-SAR', 'asset_class' => AssetClass::Cash]);

        Http::fake();

        $series = app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['CASH-SAR'], now()->subDays(5), now());

        $this->assertNotEmpty($series['CASH-SAR']);
        Http::assertNothingSent();
    }

    public function test_requests_are_throttled_to_the_free_tier_budget(): void
    {
        config(['services.twelvedata.key' => 'key-123']);
        Sleep::fake();

        Http::fake([
            'api.twelvedata.com/*' => Http::response([
                'status' => 'ok',
                'values' => [['datetime' => '2026-07-03', 'close' => '10.0']],
            ]),
        ]);

        app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['AAPL', 'MSFT', 'GOOGL'], now()->subDays(5), now());

        // No pause before the first request, one before each of the rest.
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(8)->seconds(), Sleep::for(8)->seconds()]);
    }

    public function test_api_failures_fall_back_to_the_simulated_series_per_symbol(): void
    {
        config(['services.twelvedata.key' => 'key-123']);

        Http::fake(['api.twelvedata.com/*' => Http::response(status: 500)]);

        $series = app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['AAPL'], now()->subDays(10), now());

        $this->assertNotEmpty($series['AAPL']);
    }
}
