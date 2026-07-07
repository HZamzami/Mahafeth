<?php

namespace App\Services\Analytics;

/**
 * Replays a named deterministic shock on the live portfolio: a scenario
 * combines a broad market move with harder moves on targeted sectors or
 * asset classes (config mahafeth.stress_scenarios). Each holding takes
 * the most severe shock that applies to it; the portfolio impact is the
 * weight-sum of per-position shocks.
 */
class StressScenarioAnalyzer
{
    private const TOP_POSITIONS = 5;

    /**
     * @param  array<string, float>  $weights  symbol => portfolio weight
     * @param  array<string, array{name: string, sector: ?string, asset_class: string}>  $assets  symbol => metadata
     * @param  array{market: float, targets: list<array{group: string, value: string, shock: float}>}  $scenario
     * @return array{
     *     impact: float,
     *     positions: list<array{symbol: string, name: string, weight: float, shock: float, contribution: float}>
     * }
     */
    public function apply(array $weights, array $assets, array $scenario): array
    {
        $positions = [];
        $impact = 0.0;

        foreach ($weights as $symbol => $weight) {
            $shock = $this->shockFor($assets[$symbol] ?? [], $scenario);
            $contribution = $weight * $shock;
            $impact += $contribution;

            $positions[] = [
                'symbol' => $symbol,
                'name' => $assets[$symbol]['name'] ?? $symbol,
                'weight' => $weight,
                'shock' => $shock,
                'contribution' => $contribution,
            ];
        }

        usort($positions, fn (array $a, array $b): int => $a['contribution'] <=> $b['contribution']);

        return [
            'impact' => $impact,
            'positions' => array_slice($positions, 0, self::TOP_POSITIONS),
        ];
    }

    /**
     * The most severe shock applying to the asset, defaulting to the
     * scenario's broad market move.
     *
     * @param  array{sector?: ?string, asset_class?: string}  $asset
     * @param  array{market: float, targets: list<array{group: string, value: string, shock: float}>}  $scenario
     */
    private function shockFor(array $asset, array $scenario): float
    {
        $shock = $scenario['market'];

        foreach ($scenario['targets'] as $target) {
            $matches = match ($target['group']) {
                'sector' => ($asset['sector'] ?? null) === $target['value'],
                'asset_class' => ($asset['asset_class'] ?? null) === $target['value'],
                default => false,
            };

            if ($matches) {
                $shock = min($shock, $target['shock']);
            }
        }

        return $shock;
    }
}
