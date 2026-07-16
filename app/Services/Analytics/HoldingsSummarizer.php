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
     * @return array{rows: list<array{symbol: string, name: string, quantity: float, value: float, cost: float, avgCost: ?float, pl: float, plPct: float, weight: float, shariah: ShariahStatus}>, totalValue: float, totalCost: float}
     */
    public function rows(User $user): array
    {
        $fxRates = $this->fxRates->all();

        $holdings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        /** @var array<string, array{assetId: int, rate: float, quantity: float, cost: float, name: string, shariah: ShariahStatus}> $positions */
        $positions = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->asset->symbol;
            $rate = $fxRates[$holding->asset->currency] ?? 1.0;

            $positions[$symbol] ??= [
                'assetId' => $holding->asset_id,
                'rate' => $rate,
                'quantity' => 0.0,
                'cost' => 0.0,
                'name' => $holding->asset->localizedName(),
                'shariah' => $holding->asset->shariah_status,
            ];
            $positions[$symbol]['quantity'] += (float) $holding->quantity;
            $positions[$symbol]['cost'] += $holding->quantity * $holding->avg_cost * $rate;
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
                'quantity' => $position['quantity'],
                'value' => $value,
                'cost' => $cost,
                // Per-share purchase average: the number investors compare
                // against the current price to see if they are up or down.
                'avgCost' => $position['quantity'] > 0 ? $cost / $position['quantity'] : null,
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
     * @return array{rows: list<array{holdingId: int, symbol: string, name: string, assetClass: AssetClass, quantity: float, avgCost: float, value: float, cost: float, pl: float, plPct: float, weight: float}>, totalValue: float, totalCost: float}
     */
    public function forAccount(Account $account): array
    {
        $fxRates = $this->fxRates->all();
        $holdings = $account->holdings()->with('asset')->get();
        $closes = $this->latestCloses($holdings->pluck('asset_id')->all());

        $rows = [];

        foreach ($holdings as $holding) {
            $rate = $fxRates[$holding->asset->currency] ?? 1.0;
            $close = $closes[$holding->asset_id] ?? 0.0;

            $value = $holding->quantity * $close * $rate;
            $cost = $holding->quantity * $holding->avg_cost * $rate;

            $rows[] = [
                'holdingId' => $holding->id,
                'symbol' => $holding->asset->symbol,
                'name' => $holding->asset->localizedName(),
                'assetClass' => $holding->asset->asset_class,
                'quantity' => (float) $holding->quantity,
                'avgCost' => (float) $holding->avg_cost,
                'value' => $value,
                'cost' => $cost,
                'pl' => $value - $cost,
                'plPct' => $cost > 0 ? ($value - $cost) / $cost : 0.0,
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
