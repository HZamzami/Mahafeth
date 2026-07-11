<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Holding;
use App\Models\User;
use App\Services\Fx\FxRateService;

/**
 * The user's holdings as display rows: quantity and cost aggregated across
 * accounts, valued at the latest close in base currency. Shared by the
 * report's holdings table and the holdings list page.
 */
class HoldingsSummarizer
{
    public function __construct(
        private PortfolioDataAssembler $assembler,
        private FxRateService $fxRates,
    ) {}

    /**
     * @return array{rows: list<array{symbol: string, name: string, quantity: float, value: float, cost: float, pl: float, plPct: float, weight: float, shariah: ShariahStatus}>, totalValue: float, totalCost: float}
     */
    public function rows(User $user): array
    {
        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $data = $this->assembler->forUser($user, now()->subYears($windowYears));
        $fxRates = $this->fxRates->all();

        $costs = [];
        $names = [];
        $statuses = [];

        $dbHoldings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        foreach ($dbHoldings as $holding) {
            $symbol = $holding->asset->symbol;
            $rate = $fxRates[$holding->asset->currency] ?? 1.0;
            $costs[$symbol] = ($costs[$symbol] ?? 0.0) + $holding->quantity * $holding->avg_cost * $rate;
            $names[$symbol] = $holding->asset->localizedName();
            $statuses[$symbol] = $holding->asset->shariah_status;
        }

        $rows = [];

        foreach ($data['quantities'] as $symbol => $quantity) {
            $series = $data['priceSeries'][$symbol] ?? [];

            if ($series === []) {
                continue;
            }

            $value = $quantity * end($series);
            $cost = $costs[$symbol] ?? 0.0;

            $rows[] = [
                'symbol' => $symbol,
                'name' => $names[$symbol] ?? $symbol,
                'quantity' => $quantity,
                'value' => $value,
                'cost' => $cost,
                'pl' => $value - $cost,
                'plPct' => $cost > 0 ? ($value - $cost) / $cost : 0.0,
                'shariah' => $statuses[$symbol] ?? ShariahStatus::Unknown,
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
}
