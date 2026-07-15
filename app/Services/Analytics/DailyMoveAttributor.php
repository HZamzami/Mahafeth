<?php

namespace App\Services\Analytics;

use App\Models\PortfolioSnapshot;

/**
 * Decomposes the portfolio move between two consecutive snapshots into
 * per-holding price contributions, per-currency FX contributions, and a
 * quantity-flow bucket, all as fractions of the previous total value.
 *
 * The three legs are exact: price + fx + flow sums to the value change of
 * each holding (q1·f0·Δp + q1·p1·Δf + Δq·f0·p0 = q1f1p1 − q0f0p0).
 */
class DailyMoveAttributor
{
    /**
     * @return array{
     *     as_of: string,
     *     previous_as_of: string,
     *     total_change_pct: float,
     *     contributions: list<array{symbol: string, name: string, pct: float}>,
     *     fx: list<array{currency: string, pct: float}>,
     *     flows_pct: float
     * }|null null when either snapshot predates per-holding tracking
     */
    public function attribute(?PortfolioSnapshot $current, ?PortfolioSnapshot $previous): ?array
    {
        $currentHoldings = $current?->metrics['holdings'] ?? null;
        $previousHoldings = $previous?->metrics['holdings'] ?? null;
        $previousTotal = (float) ($previous?->total_value ?? 0.0);

        if ($currentHoldings === null || $previousHoldings === null || $previousTotal <= 0) {
            return null;
        }

        $baseCurrency = config('mahafeth.base_currency');
        $contributions = [];
        $fxBuckets = [];
        $flows = 0.0;

        foreach ($currentHoldings as $symbol => $now) {
            $before = $previousHoldings[$symbol] ?? null;

            if ($before === null) {
                // Position appeared between snapshots: pure flow.
                $flows += $now['value'] / $previousTotal;

                continue;
            }

            $priceEffect = $now['quantity'] * $before['fx_rate'] * ($now['native_close'] - $before['native_close']) / $previousTotal;
            $fxEffect = $now['quantity'] * $now['native_close'] * ($now['fx_rate'] - $before['fx_rate']) / $previousTotal;
            $flows += ($now['quantity'] - $before['quantity']) * $before['fx_rate'] * $before['native_close'] / $previousTotal;

            if ($priceEffect !== 0.0) {
                $contributions[] = [
                    'symbol' => $symbol,
                    'name' => $now['name'] ?? $symbol,
                    'pct' => $priceEffect,
                ];
            }

            $currency = $now['currency'] ?? $baseCurrency;

            if ($currency !== $baseCurrency) {
                $fxBuckets[$currency] = ($fxBuckets[$currency] ?? 0.0) + $fxEffect;
            }
        }

        foreach ($previousHoldings as $symbol => $before) {
            if (! isset($currentHoldings[$symbol])) {
                // Position disappeared between snapshots: pure flow.
                $flows -= $before['value'] / $previousTotal;
            }
        }

        usort($contributions, fn (array $a, array $b): int => abs($b['pct']) <=> abs($a['pct']));

        $fx = collect($fxBuckets)
            ->filter(fn (float $pct): bool => abs($pct) >= 0.00005)
            ->map(fn (float $pct, string $currency): array => ['currency' => $currency, 'pct' => $pct])
            ->values()
            ->all();

        return [
            'as_of' => $current->as_of->toDateString(),
            'previous_as_of' => $previous->as_of->toDateString(),
            'total_change_pct' => ((float) $current->total_value - $previousTotal) / $previousTotal,
            'contributions' => $contributions,
            'fx' => $fx,
            'flows_pct' => $flows,
        ];
    }
}
