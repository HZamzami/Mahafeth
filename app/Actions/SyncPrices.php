<?php

namespace App\Actions;

use App\Contracts\OpenBankingProvider;
use App\Models\Asset;
use App\Models\PriceHistory;
use Illuminate\Support\Carbon;

/**
 * Pulls daily close series for the given symbols from the Open Banking
 * provider into the local price history. Shared by connection syncs and
 * the statement import path.
 */
class SyncPrices
{
    public const PRICE_HISTORY_YEARS = 3;

    public function __construct(private OpenBankingProvider $provider) {}

    /**
     * @param  list<string>  $symbols
     */
    public function handle(array $symbols): void
    {
        $from = Carbon::now()->subYears(self::PRICE_HISTORY_YEARS)->startOfDay();
        $to = Carbon::now()->startOfDay();

        $assetIds = Asset::whereIn('symbol', $symbols)->pluck('id', 'symbol');
        $series = $this->provider->fetchPrices($symbols, $from, $to);

        foreach ($series as $symbol => $prices) {
            $rows = [];

            foreach ($prices as $date => $close) {
                $rows[] = [
                    'asset_id' => $assetIds[$symbol],
                    'date' => $date,
                    'close' => $close,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                PriceHistory::upsert($chunk, ['asset_id', 'date'], ['close']);
            }
        }
    }
}
