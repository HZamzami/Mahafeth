<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\FxRate;
use App\Services\Fx\FxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FxRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_falls_back_to_the_configured_peg_without_fetched_rates(): void
    {
        $service = app(FxRateService::class);
        $service->flush();

        $this->assertEqualsWithDelta(3.75, $service->rate('USD'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $service->rate('SAR'), 1e-9);
    }

    public function test_fetched_rates_override_the_configured_peg(): void
    {
        FxRate::factory()->create(['currency' => 'USD', 'rate' => 3.7501]);

        $service = app(FxRateService::class);
        $service->flush();

        $this->assertEqualsWithDelta(3.7501, $service->rate('USD'), 1e-9);
    }

    public function test_the_fetch_command_stores_inverted_rates_for_asset_currencies(): void
    {
        Asset::factory()->create(['currency' => 'USD']);
        Asset::factory()->create(['currency' => 'GBP']);

        Http::fake([
            'open.er-api.com/*' => Http::response([
                'result' => 'success',
                // 1 SAR buys 0.2667 USD, i.e. 1 USD = 3.7495 SAR.
                'rates' => ['SAR' => 1.0, 'USD' => 0.2667, 'GBP' => 0.21],
            ]),
        ]);

        $this->artisan('mahafeth:fetch-fx-rates')->assertSuccessful();

        $this->assertEqualsWithDelta(1 / 0.2667, FxRate::where('currency', 'USD')->first()->rate, 1e-6);
        $this->assertEqualsWithDelta(1 / 0.21, FxRate::where('currency', 'GBP')->first()->rate, 1e-6);
        $this->assertFalse(FxRate::where('currency', 'SAR')->exists());
    }

    public function test_a_failed_fetch_leaves_existing_rates_untouched(): void
    {
        FxRate::factory()->create(['currency' => 'USD', 'rate' => 3.75]);

        Http::fake(['open.er-api.com/*' => Http::response(status: 500)]);

        $this->artisan('mahafeth:fetch-fx-rates')->assertFailed();

        $this->assertEqualsWithDelta(3.75, FxRate::where('currency', 'USD')->first()->rate, 1e-9);
    }
}
