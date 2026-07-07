<?php

namespace App\Services\Analytics;

/**
 * Zakat due on the unified portfolio, on the trading-portfolio basis:
 * the configured rate applies to the market value of zakatable asset
 * classes once total zakatable wealth meets the nisab threshold. Bonds
 * and real estate are excluded here so they can get their own treatment
 * (income-based) later.
 */
class ZakatCalculator
{
    private const ZAKATABLE_CLASSES = ['cash', 'equity', 'fund', 'crypto'];

    /**
     * @param  array<string, float>  $values  symbol => current value in base currency
     * @param  array<string, array{asset_class: string}>  $assets  symbol => metadata
     * @return array{zakatable_value: float, zakat_due: float, below_nisab: bool}
     */
    public function calculate(array $values, array $assets): array
    {
        $zakatable = 0.0;

        foreach ($values as $symbol => $value) {
            $assetClass = $assets[$symbol]['asset_class'] ?? null;

            if (in_array($assetClass, self::ZAKATABLE_CLASSES, true)) {
                $zakatable += $value;
            }
        }

        $meetsNisab = $zakatable >= (float) config('mahafeth.zakat.nisab');

        return [
            'zakatable_value' => round($zakatable, 2),
            'zakat_due' => $meetsNisab ? round($zakatable * (float) config('mahafeth.zakat.rate'), 2) : 0.0,
            'below_nisab' => ! $meetsNisab,
        ];
    }
}
