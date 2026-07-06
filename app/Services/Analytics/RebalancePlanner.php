<?php

namespace App\Services\Analytics;

use App\Enums\ShariahStatus;

/**
 * Turns the gap between the current allocation and the optimal (tangency)
 * allocation into concrete orders: how many units of each holding to buy
 * or sell at the latest price. Shariah-constrained investors never receive
 * buy orders for non-compliant assets; that budget is redistributed across
 * the remaining compliant buys so the plan stays cash-neutral.
 */
class RebalancePlanner
{
    /**
     * @param  array<string, float>  $currentWeights  symbol => weight
     * @param  array<string, float>  $targetWeights  symbol => weight
     * @param  array<string, float>  $quantities  symbol => units held
     * @param  array<string, array{name: string, shariah_status?: string}>  $assets  symbol => metadata
     * @return list<array{symbol: string, name: string, side: string, quantity: float, value: float, current_weight: float, target_weight: float}>
     */
    public function plan(
        array $currentWeights,
        array $targetWeights,
        float $totalValue,
        array $quantities,
        array $assets,
        bool $shariahRequired = false,
        ?float $minTradeValue = null,
    ): array {
        $minTradeValue ??= (float) config('mahafeth.min_trade_value');

        if ($totalValue <= 0) {
            return [];
        }

        $orders = [];
        $excludedBuyBudget = 0.0;

        foreach (array_keys($currentWeights + $targetWeights) as $symbol) {
            $current = $currentWeights[$symbol] ?? 0.0;
            $target = $targetWeights[$symbol] ?? 0.0;
            $quantity = $quantities[$symbol] ?? 0.0;

            // Latest price implied by the holding's current valuation.
            if ($quantity <= 0 || $current <= 0) {
                continue;
            }

            $price = ($current * $totalValue) / $quantity;
            $delta = ($target - $current) * $totalValue;
            $status = $assets[$symbol]['shariah_status'] ?? ShariahStatus::Unknown->value;

            if ($shariahRequired && $delta > 0 && $status === ShariahStatus::NonCompliant->value) {
                $excludedBuyBudget += $delta;

                continue;
            }

            $orders[$symbol] = [
                'symbol' => $symbol,
                'name' => $assets[$symbol]['name'] ?? $symbol,
                'side' => $delta >= 0 ? 'buy' : 'sell',
                'quantity' => abs($delta) / $price,
                'value' => abs($delta),
                'price' => $price,
                'current_weight' => $current,
                'target_weight' => $target,
            ];
        }

        // Spread any excluded non-compliant buy budget proportionally over
        // the remaining buys so sells and buys still balance.
        if ($excludedBuyBudget > 0) {
            $buyTotal = array_sum(array_map(
                fn (array $order): float => $order['side'] === 'buy' ? $order['value'] : 0.0,
                $orders,
            ));

            foreach ($orders as $symbol => $order) {
                if ($order['side'] === 'buy' && $buyTotal > 0) {
                    $extra = $excludedBuyBudget * ($order['value'] / $buyTotal);
                    $orders[$symbol]['value'] += $extra;
                    $orders[$symbol]['quantity'] += $extra / $order['price'];
                }
            }
        }

        $orders = array_values(array_filter(
            $orders,
            fn (array $order): bool => $order['value'] >= $minTradeValue,
        ));

        usort($orders, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_map(function (array $order): array {
            unset($order['price']);

            $order['quantity'] = round($order['quantity'], 4);
            $order['value'] = round($order['value'], 2);

            return $order;
        }, $orders);
    }
}
