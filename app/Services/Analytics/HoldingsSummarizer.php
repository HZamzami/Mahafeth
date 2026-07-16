<?php

namespace App\Services\Analytics;

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Account;
use App\Models\Holding;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\Fx\FxRateService;

/**
 * The user's holdings as display rows: quantity and cost aggregated across
 * accounts, valued at the latest close in base currency. Shared by the
 * report's holdings table and the holdings list page.
 *
 * Deliberately reads only the latest close per asset instead of going
 * through PortfolioDataAssembler, which loads a full year of history the
 * list never uses — that was most of the holdings page's render time.
 */
class HoldingsSummarizer
{
    public function __construct(private FxRateService $fxRates) {}

    /**
     * @return array{rows: list<array{symbol: string, name: string, currency: string, quantity: float, value: float, cost: float, avgCost: ?float, nativeValue: float, nativeCost: float, pl: float, plPct: float, weight: float, shariah: ShariahStatus}>, totalValue: float, totalCost: float}
     */
    public function rows(User $user): array
    {
        $fxRates = $this->fxRates->all();

        $holdings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        /** @var array<string, array{assetId: int, currency: string, rate: float, quantity: float, cost: float, nativeCost: float, name: string, shariah: ShariahStatus}> $positions */
        $positions = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->asset->symbol;
            $rate = $fxRates[$holding->asset->currency] ?? 1.0;

            $positions[$symbol] ??= [
                'assetId' => $holding->asset_id,
                'currency' => $holding->asset->currency,
                'rate' => $rate,
                'quantity' => 0.0,
                'cost' => 0.0,
                'nativeCost' => 0.0,
                'name' => $holding->asset->localizedName(),
                'shariah' => $holding->asset->shariah_status,
            ];
            $positions[$symbol]['quantity'] += (float) $holding->quantity;
            $positions[$symbol]['cost'] += $holding->quantity * $holding->avg_cost * $rate;
            $positions[$symbol]['nativeCost'] += $holding->quantity * $holding->avg_cost;
        }

        $closes = $this->latestCloses(array_column($positions, 'assetId'));

        $rows = [];

        foreach ($positions as $symbol => $position) {
            $close = $closes[$position['assetId']] ?? null;

            // No stored price yet: same as before, the position stays off
            // the list until the first sync lands a close.
            if ($close === null) {
                continue;
            }

            $value = $position['quantity'] * $close * $position['rate'];
            $cost = $position['cost'];

            $rows[] = [
                'symbol' => $symbol,
                'name' => $position['name'],
                'currency' => $position['currency'],
                'quantity' => $position['quantity'],
                'value' => $value,
                'cost' => $cost,
                // Per-share purchase average: the number investors compare
                // against the current price to see if they are up or down.
                'avgCost' => $position['quantity'] > 0 ? $cost / $position['quantity'] : null,
                // Value and cost in the asset's own currency, so a row can read
                // in the currency it was actually bought in.
                'nativeValue' => $position['quantity'] * $close,
                'nativeCost' => $position['nativeCost'],
                'pl' => $value - $cost,
                'plPct' => $cost > 0 ? ($value - $cost) / $cost : 0.0,
                'shariah' => $position['shariah'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $totalValue = array_sum(array_column($rows, 'value'));

        $rows = array_map(function (array $row) use ($totalValue): array {
            $row['weight'] = $totalValue > 0 ? $row['value'] / $totalValue : 0.0;

            return $row;
        }, $rows);

        return [
            'rows' => $rows,
            'totalValue' => $totalValue,
            'totalCost' => array_sum(array_column($rows, 'cost')),
        ];
    }

    /**
     * One account's positions as editable rows: each holding kept distinct
     * (with its id) and valued at the latest close, weighted within the
     * account. Positions without a stored close yet still list, valued at 0,
     * so a just-added holding never vanishes from the editor. Each row also
     * carries its cost basis and unrealized profit/loss, in base currency, so
     * the page can show market value against what was paid.
     *
     * Values are in base currency; each row also carries its native-currency
     * value and cost (the currency the asset trades in). When every holding
     * shares one currency, `currency` and the native totals are set so the page
     * can show what was paid natively; a mixed account leaves them null.
     *
     * @return array{rows: list<array{holdingId: int, symbol: string, name: string, currency: string, assetClass: AssetClass, quantity: float, avgCost: float, value: float, cost: float, nativeValue: float, nativeCost: float, pl: float, plPct: float, weight: float}>, totalValue: float, totalCost: float, currency: ?string, nativeTotalValue: ?float, nativeTotalCost: ?float}
     */
    public function forAccount(Account $account): array
    {
        $fxRates = $this->fxRates->all();
        $holdings = $account->holdings()->with('asset')->get();
        $closes = $this->latestCloses($holdings->pluck('asset_id')->all());

        $rows = [];

        foreach ($holdings as $holding) {
            $currency = $holding->asset->currency;
            $rate = $fxRates[$currency] ?? 1.0;
            $close = $closes[$holding->asset_id] ?? 0.0;

            $nativeValue = $holding->quantity * $close;
            $nativeCost = $holding->quantity * $holding->avg_cost;

            $rows[] = [
                'holdingId' => $holding->id,
                'symbol' => $holding->asset->symbol,
                'name' => $holding->asset->localizedName(),
                'currency' => $currency,
                'assetClass' => $holding->asset->asset_class,
                'quantity' => (float) $holding->quantity,
                'avgCost' => (float) $holding->avg_cost,
                'value' => $nativeValue * $rate,
                'cost' => $nativeCost * $rate,
                'nativeValue' => $nativeValue,
                'nativeCost' => $nativeCost,
                'pl' => ($nativeValue - $nativeCost) * $rate,
                'plPct' => $nativeCost > 0 ? ($nativeValue - $nativeCost) / $nativeCost : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $totalValue = array_sum(array_column($rows, 'value'));

        $rows = array_map(function (array $row) use ($totalValue): array {
            $row['weight'] = $totalValue > 0 ? $row['value'] / $totalValue : 0.0;

            return $row;
        }, $rows);

        // Only a single-currency account has a meaningful native total; summing
        // native amounts across currencies would be nonsense.
        $currencies = array_values(array_unique(array_column($rows, 'currency')));
        $singleCurrency = count($currencies) === 1 ? $currencies[0] : null;

        return [
            'rows' => $rows,
            'totalValue' => $totalValue,
            'totalCost' => array_sum(array_column($rows, 'cost')),
            'currency' => $singleCurrency,
            'nativeTotalValue' => $singleCurrency !== null ? array_sum(array_column($rows, 'nativeValue')) : null,
            'nativeTotalCost' => $singleCurrency !== null ? array_sum(array_column($rows, 'nativeCost')) : null,
        ];
    }

    /**
     * The most recent stored close per asset, in native currency.
     *
     * @param  list<int>  $assetIds
     * @return array<int, float> asset id => close
     */
    private function latestCloses(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $latestDates = PriceHistory::query()
            ->selectRaw('asset_id, MAX(date) AS max_date')
            ->whereIn('asset_id', $assetIds)
            ->groupBy('asset_id');

        return PriceHistory::query()
            ->joinSub($latestDates, 'latest', fn ($join) => $join
                ->on('price_histories.asset_id', '=', 'latest.asset_id')
                ->on('price_histories.date', '=', 'latest.max_date'))
            ->pluck('price_histories.close', 'price_histories.asset_id')
            ->map(fn ($close): float => (float) $close)
            ->all();
    }
}
