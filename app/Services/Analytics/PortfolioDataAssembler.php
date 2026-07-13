<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Holding;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fx\FxRateService;
use Carbon\CarbonInterface;

/**
 * Assembles a user's unified portfolio (quantities per symbol, asset
 * metadata, and historical price series) from the database into the plain
 * arrays the analytics services operate on.
 *
 * Prices are stored in each asset's native currency and converted to the
 * base currency (config mahafeth.base_currency) here, at read time — so
 * every downstream value, weight, and metric is in one currency.
 */
class PortfolioDataAssembler
{
    public function __construct(private FxRateService $fxRateService) {}

    /**
     * @return array{
     *     quantities: array<string, float>,
     *     priceSeries: array<string, array<string, float>>,
     *     assets: array<string, array{name: string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status: string}>,
     *     dividends: array<string, float>
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
        $sources = [];
        $assets = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->asset->symbol;
            $quantities[$symbol] = ($quantities[$symbol] ?? 0.0) + $holding->quantity;
            $sources[$symbol] = ['id' => $holding->asset_id, 'rate' => $this->fxRate($holding->asset->currency)];

            $assets[$symbol] = [
                'name' => $holding->asset->localizedName(),
                'asset_class' => $holding->asset->asset_class->value,
                'sector' => $holding->asset->sector,
                'country' => $holding->asset->country,
                'currency' => $holding->asset->currency,
                'shariah_status' => $holding->asset->shariah_status->value,
            ];
        }

        return [
            'quantities' => $quantities,
            'priceSeries' => $this->priceSeries($sources, $from),
            'assets' => $assets,
            'dividends' => $this->trailingDividends($user),
        ];
    }

    /**
     * Dividends received per symbol over the trailing year, in base currency.
     *
     * @return array<string, float>
     */
    private function trailingDividends(User $user): array
    {
        $dividends = Transaction::with('asset')
            ->where('type', TransactionType::Dividend)
            ->where('executed_at', '>=', now()->subYear())
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        $sums = [];

        foreach ($dividends as $transaction) {
            if ($transaction->asset === null) {
                continue;
            }

            $symbol = $transaction->asset->symbol;
            $sums[$symbol] = ($sums[$symbol] ?? 0.0)
                + $transaction->amount * $this->fxRate($transaction->asset->currency);
        }

        return $sums;
    }

    /**
     * Daily close series for the primary benchmark index, in base currency.
     *
     * @return array<string, float> date => close
     */
    public function benchmarkSeries(CarbonInterface $from): array
    {
        $series = $this->benchmarkSeriesFor([config('mahafeth.benchmark_symbol')], $from);

        return reset($series) ?: [];
    }

    /**
     * Daily close series for several benchmark indices, in base currency.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, float>> symbol => [date => close]
     */
    public function benchmarkSeriesFor(array $symbols, CarbonInterface $from): array
    {
        $sources = Asset::whereIn('symbol', $symbols)
            ->where('is_benchmark', true)
            ->get()
            ->mapWithKeys(fn (Asset $asset) => [
                $asset->symbol => ['id' => $asset->id, 'rate' => $this->fxRate($asset->currency)],
            ])
            ->all();

        return $this->priceSeries($sources, $from);
    }

    /**
     * Daily close series for any catalog symbols, in base currency —
     * benchmark or not. Used to build candidate universes (e.g. the
     * investment-plan optimizer) independent of anyone's holdings.
     *
     * @param  list<string>  $symbols
     * @return array<string, array<string, float>> symbol => [date => close]
     */
    public function seriesFor(array $symbols, CarbonInterface $from): array
    {
        $sources = Asset::whereIn('symbol', $symbols)
            ->get()
            ->mapWithKeys(fn (Asset $asset) => [
                $asset->symbol => ['id' => $asset->id, 'rate' => $this->fxRate($asset->currency)],
            ])
            ->all();

        return $this->priceSeries($sources, $from);
    }

    /**
     * Base-currency units per one unit of the given currency.
     */
    private function fxRate(string $currency): float
    {
        return $this->fxRateService->rate($currency);
    }

    /**
     * @param  array<string, array{id: int, rate: float}>  $sources  symbol => asset id + fx rate
     * @return array<string, array<string, float>> symbol => [date => close in base currency]
     */
    private function priceSeries(array $sources, CarbonInterface $from): array
    {
        if ($sources === []) {
            return [];
        }

        $prices = PriceHistory::whereIn('asset_id', array_column($sources, 'id'))
            ->where('date', '>=', $from->toDateString())
            ->orderBy('date')
            ->get(['asset_id', 'date', 'close'])
            ->groupBy('asset_id');

        $priceSeries = [];

        foreach ($sources as $symbol => $source) {
            $series = ($prices[$source['id']] ?? collect())
                ->mapWithKeys(fn (PriceHistory $price) => [
                    $price->date->toDateString() => $price->close * $source['rate'],
                ])
                ->all();

            if ($series !== []) {
                $priceSeries[$symbol] = $series;
            }
        }

        return $priceSeries;
    }
}
