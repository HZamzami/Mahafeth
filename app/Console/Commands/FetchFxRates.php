<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\FxRate;
use App\Services\Fx\FxRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchFxRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:fetch-fx-rates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch daily FX rates into the base currency from the open exchange-rate API';

    /**
     * Execute the console command.
     */
    public function handle(FxRateService $fxRateService): int
    {
        $base = config('mahafeth.base_currency');
        $endpoint = rtrim(config('services.fx.base_url'), '/')."/v6/latest/{$base}";

        $response = Http::timeout(15)->retry(2, 500, throw: false)->get($endpoint);

        if ($response->failed() || $response->json('result') !== 'success') {
            $this->components->error('FX rate fetch failed; existing rates remain in effect.');

            return self::FAILURE;
        }

        // The API returns base-per-currency (1 SAR = X units); valuation
        // needs the inverse (1 unit = Y SAR).
        $published = $response->json('rates', []);
        $currencies = Asset::query()->distinct()->pluck('currency')->push('USD')->unique();
        $stored = 0;

        foreach ($currencies as $currency) {
            $perBase = (float) ($published[$currency] ?? 0);

            if ($currency === $base || $perBase <= 0) {
                continue;
            }

            FxRate::updateOrCreate(
                ['currency' => $currency],
                ['rate' => 1 / $perBase, 'fetched_at' => now()],
            );

            $stored++;
        }

        $fxRateService->flush();
        $this->components->info("Stored {$stored} rates against {$base}.");

        return self::SUCCESS;
    }
}
