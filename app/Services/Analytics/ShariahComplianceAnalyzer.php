<?php

namespace App\Services\Analytics;

use App\Enums\ShariahStatus;

/**
 * Screens the unified portfolio against per-asset Shariah classifications:
 * how much of the portfolio's value sits in compliant, non-compliant, and
 * unclassified assets, which positions are flagged, and how much income
 * must be purified (donated). Purification follows the practical method
 * used when haram-income ratios are unavailable: all dividends received
 * from non-compliant holdings over the trailing year.
 */
class ShariahComplianceAnalyzer
{
    /**
     * @param  array<string, float>  $weights  symbol => portfolio weight
     * @param  array<string, array{name: string, shariah_status?: string}>  $assets  symbol => metadata
     * @param  array<string, float>  $dividends  symbol => trailing-year dividends in base currency
     * @return array{
     *     compliant_weight: float,
     *     non_compliant_weight: float,
     *     unknown_weight: float,
     *     purification_amount: float,
     *     non_compliant_positions: list<array{symbol: string, name: string, weight: float, purification: float}>
     * }
     */
    public function analyze(array $weights, array $assets, array $dividends = []): array
    {
        $buckets = [
            ShariahStatus::Compliant->value => 0.0,
            ShariahStatus::NonCompliant->value => 0.0,
            ShariahStatus::Unknown->value => 0.0,
        ];
        $flagged = [];

        foreach ($weights as $symbol => $weight) {
            $status = $assets[$symbol]['shariah_status'] ?? ShariahStatus::Unknown->value;
            $buckets[$status] = ($buckets[$status] ?? 0.0) + $weight;

            if ($status === ShariahStatus::NonCompliant->value) {
                $flagged[] = [
                    'symbol' => $symbol,
                    'name' => $assets[$symbol]['name'] ?? $symbol,
                    'weight' => $weight,
                    'purification' => round($dividends[$symbol] ?? 0.0, 2),
                ];
            }
        }

        usort($flagged, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'compliant_weight' => $buckets[ShariahStatus::Compliant->value],
            'non_compliant_weight' => $buckets[ShariahStatus::NonCompliant->value],
            'unknown_weight' => $buckets[ShariahStatus::Unknown->value],
            'purification_amount' => round(array_sum(array_column($flagged, 'purification')), 2),
            'non_compliant_positions' => $flagged,
        ];
    }
}
