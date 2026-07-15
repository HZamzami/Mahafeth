<?php

namespace App\Actions;

use App\Contracts\PriceProvider;
use App\Models\Asset;
use App\Models\PriceHistory;
use App\Services\OpenBanking\AssetCatalog;
use App\Services\OpenBanking\PriceSeriesGenerator;
use Illuminate\Support\Carbon;

/**
 * Pulls daily close series for the given symbols from the price provider
 * into the local price history. Shared by connection syncs and the
 * statement import path. Any symbol the provider can't serve (no key, an
 * API failure, or an uncatalogued symbol under the simulated provider)
 * falls back to a synthetic series so the analytics pipeline always has at
 * least two closes to value the position.
 */
class SyncPrices
{
    public const PRICE_HISTORY_YEARS = 3;

    private const MINIMUM_ROWS = 2;

    public function __construct(
        private PriceProvider $provider,
        private PriceSeriesGenerator $seriesGenerator,
        private AssetCatalog $catalog,
    ) {}

    /**
     * @param  list<string>  $symbols
     * @param  array<string, float>  $seedPrices  symbol => seed price for the synthetic fallback
     */
    public function handle(array $symbols, array $seedPrices = []): void
    {
        $from = Carbon::now()->subYears(self::PRICE_HISTORY_YEARS)->startOfDay();
        $to = Carbon::now()->startOfDay();

        $assetIds = Asset::whereIn('symbol', $symbols)->pluck('id', 'symbol');
        $series = $this->provider->fetchDailyCloses($symbols, $from, $to);

        foreach ($symbols as $symbol) {
            if (! isset($assetIds[$symbol])) {
                continue;
            }

            $prices = $series[$symbol] ?? [];

            if ($prices !== []) {
                $this->replaceHistory($assetIds[$symbol], $prices);
            }

            if (PriceHistory::where('asset_id', $assetIds[$symbol])->count() < self::MINIMUM_ROWS) {
                $seed = $seedPrices[$symbol]
                    ?? $this->catalog->simulationParams($symbol)['start']
                    ?? 100.0;

                $this->replaceHistory($assetIds[$symbol], $this->seriesGenerator->synthetic($symbol, $seed));
            }
        }
    }

    /**
     * Replace an asset's stored history with the given series. Rows on dates
     * the series does not cover are leftovers from another provider (e.g.
     * simulated closes on real market holidays) or from older sync windows;
     * mixing sources zigzags the series and blows up every volatility-derived
     * metric, so the series replaces the asset's history outright.
     *
     * @param  array<string, float>  $prices  [Y-m-d => close]
     */
    private function replaceHistory(int $assetId, array $prices): void
    {
        $rows = [];

        foreach ($prices as $date => $close) {
            $rows[] = [
                'asset_id' => $assetId,
                'date' => $date,
                'close' => $close,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PriceHistory::upsert($chunk, ['asset_id', 'date'], ['close']);
        }

        PriceHistory::where('asset_id', $assetId)
            ->whereNotIn('date', array_keys($prices))
            ->delete();
    }
}
