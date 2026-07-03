<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Models\Asset;
use App\Models\Holding;
use App\Models\PriceHistory;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Assembles a user's unified portfolio (quantities per symbol, asset
 * metadata, and historical price series) from the database into the plain
 * arrays the analytics services operate on.
 */
class PortfolioDataAssembler
{
    /**
     * @return array{
     *     quantities: array<string, float>,
     *     priceSeries: array<string, array<string, float>>,
     *     assets: array<string, array{name: string, asset_class: string, sector: ?string, country: ?string, currency: string}>
     * }
     */
    public function forUser(User $user, CarbonInterface $from): array
    {
        $holdings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        $quantities = [];
        $assetIds = [];
        $assets = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->asset->symbol;
            $quantities[$symbol] = ($quantities[$symbol] ?? 0.0) + $holding->quantity;
            $assetIds[$symbol] = $holding->asset_id;

            $assets[$symbol] = [
                'name' => $holding->asset->localizedName(),
                'asset_class' => $holding->asset->asset_class->value,
                'sector' => $holding->asset->sector,
                'country' => $holding->asset->country,
                'currency' => $holding->asset->currency,
            ];
        }

        return [
            'quantities' => $quantities,
            'priceSeries' => $this->priceSeries($assetIds, $from),
            'assets' => $assets,
        ];
    }

    /**
     * Daily close series for the configured benchmark index.
     *
     * @return array<string, float> date => close
     */
    public function benchmarkSeries(CarbonInterface $from): array
    {
        $benchmark = Asset::where('symbol', config('mahafeth.benchmark_symbol'))
            ->where('is_benchmark', true)
            ->first();

        if ($benchmark === null) {
            return [];
        }

        $series = $this->priceSeries([$benchmark->symbol => $benchmark->id], $from);

        return $series[$benchmark->symbol] ?? [];
    }

    /**
     * @param  array<string, int>  $assetIds  symbol => asset id
     * @return array<string, array<string, float>> symbol => [date => close]
     */
    private function priceSeries(array $assetIds, CarbonInterface $from): array
    {
        if ($assetIds === []) {
            return [];
        }

        $prices = PriceHistory::whereIn('asset_id', array_values($assetIds))
            ->where('date', '>=', $from->toDateString())
            ->orderBy('date')
            ->get(['asset_id', 'date', 'close'])
            ->groupBy('asset_id');

        $priceSeries = [];

        foreach ($assetIds as $symbol => $assetId) {
            $series = ($prices[$assetId] ?? collect())
                ->mapWithKeys(fn (PriceHistory $price) => [$price->date->toDateString() => $price->close])
                ->all();

            if ($series !== []) {
                $priceSeries[$symbol] = $series;
            }
        }

        return $priceSeries;
    }
}
