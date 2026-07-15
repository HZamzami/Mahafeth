<?php

namespace App\Services\Analytics;

use App\Enums\ShariahStatus;

/**
 * Screens the unified portfolio against per-asset Shariah classifications:
 * how much of the portfolio's value sits in compliant, non-compliant, and
 * unclassified assets, which positions are flagged, and how much income
 * must be purified (donated).
 *
 * Each asset's share of a dividend needing purification is its
 * purification_rate when a Shariah board has published one; otherwise the
 * practical method applies — everything from non-compliant holdings,
 * nothing from compliant ones. Outstanding purification accrues from the
 * dividends received after the user's last settlement, so an obligation
 * that was paid stays settled until new impure income arrives.
 */
class ShariahComplianceAnalyzer
{
    /**
     * @param  array<string, float>  $weights  symbol => portfolio weight
     * @param  array<string, array{name: string, shariah_status?: string, purification_rate?: ?float}>  $assets  symbol => metadata
     * @param  array<string, float>  $dividends  symbol => trailing-year dividends in base currency
     * @param  array<string, float>|null  $dividendsSinceSettlement  symbol => dividends since the last purification settlement; null means never settled (use $dividends)
     * @return array{
     *     compliant_weight: float,
     *     non_compliant_weight: float,
     *     unknown_weight: float,
     *     purification_amount: float,
     *     purification_outstanding: float,
     *     non_compliant_positions: list<array{symbol: string, name: string, weight: float, purification: float, outstanding: float}>,
     *     mixed_positions: list<array{symbol: string, name: string, weight: float, rate: float, outstanding: float}>
     * }
     */
    public function analyze(array $weights, array $assets, array $dividends = [], ?array $dividendsSinceSettlement = null): array
    {
        $unsettled = $dividendsSinceSettlement ?? $dividends;

        $buckets = [
            ShariahStatus::Compliant->value => 0.0,
            ShariahStatus::NonCompliant->value => 0.0,
            ShariahStatus::Unknown->value => 0.0,
        ];
        $flagged = [];
        $mixed = [];

        foreach ($weights as $symbol => $weight) {
            $status = $assets[$symbol]['shariah_status'] ?? ShariahStatus::Unknown->value;
            $buckets[$status] = ($buckets[$status] ?? 0.0) + $weight;

            $rate = $this->effectiveRate($assets[$symbol] ?? [], $status);

            if ($status === ShariahStatus::NonCompliant->value) {
                $flagged[] = [
                    'symbol' => $symbol,
                    'name' => $assets[$symbol]['name'] ?? $symbol,
                    'weight' => $weight,
                    'purification' => round($rate * ($dividends[$symbol] ?? 0.0), 2),
                    'outstanding' => round($rate * ($unsettled[$symbol] ?? 0.0), 2),
                ];
            } elseif ($rate > 0.0) {
                // A compliant asset with a published purification rate:
                // permissible to hold, but a slice of its income is impure.
                $mixed[] = [
                    'symbol' => $symbol,
                    'name' => $assets[$symbol]['name'] ?? $symbol,
                    'weight' => $weight,
                    'rate' => $rate,
                    'outstanding' => round($rate * ($unsettled[$symbol] ?? 0.0), 2),
                ];
            }
        }

        usort($flagged, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        usort($mixed, fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        return [
            'compliant_weight' => $buckets[ShariahStatus::Compliant->value],
            'non_compliant_weight' => $buckets[ShariahStatus::NonCompliant->value],
            'unknown_weight' => $buckets[ShariahStatus::Unknown->value],
            'purification_amount' => round(array_sum(array_column($flagged, 'purification')), 2),
            'purification_outstanding' => round(
                array_sum(array_column($flagged, 'outstanding')) + array_sum(array_column($mixed, 'outstanding')),
                2,
            ),
            'non_compliant_positions' => $flagged,
            'mixed_positions' => $mixed,
        ];
    }

    /**
     * @param  array{purification_rate?: ?float}  $asset
     */
    private function effectiveRate(array $asset, string $status): float
    {
        $rate = $asset['purification_rate'] ?? null;

        if ($rate !== null) {
            return max(0.0, min(1.0, (float) $rate));
        }

        return $status === ShariahStatus::NonCompliant->value ? 1.0 : 0.0;
    }
}
