<?php

namespace Tests\Feature;

use App\Contracts\PriceProvider;
use App\Services\Prices\SimulatedPriceProvider;
use App\Services\Prices\TwelveDataPriceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_api_failures_fall_back_to_the_simulated_series_per_symbol(): void
    {
        config(['services.twelvedata.key' => 'key-123']);

        Http::fake(['api.twelvedata.com/*' => Http::response(status: 500)]);

        $series = app(TwelveDataPriceProvider::class)
            ->fetchDailyCloses(['AAPL'], now()->subDays(10), now());

        $this->assertNotEmpty($series['AAPL']);
    }
}
