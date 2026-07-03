<?php

namespace App\Services\Analytics;

use App\Models\Holding;
use App\Models\PriceHistory;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Assembles a user's unified portfolio (quantities per symbol and historical
 * price series) from the database into the plain arrays the analytics
 * services operate on.
 */
class PortfolioDataAssembler
{
    /**
     * @return array{quantities: array<string, float>, priceSeries: array<string, array<string, float>>}
     */
    public function forUser(User $user, CarbonInterface $from): array
    {
        $holdings = Holding::with('asset:id,symbol')
            ->whereHas('account.connection', fn ($query) => $query->whereBelongsTo($user))
            ->get();

        $quantities = [];
        $assetIds = [];

        foreach ($holdings as $holding) {
            $symbol = $holding->asset->symbol;
            $quantities[$symbol] = ($quantities[$symbol] ?? 0.0) + $holding->quantity;
            $assetIds[$symbol] = $holding->asset_id;
        }

        $priceSeries = [];

        if ($assetIds !== []) {
            $prices = PriceHistory::whereIn('asset_id', array_values($assetIds))
                ->where('date', '>=', $from->toDateString())
                ->orderBy('date')
                ->get(['asset_id', 'date', 'close'])
                ->groupBy('asset_id');

            foreach ($assetIds as $symbol => $assetId) {
                $priceSeries[$symbol] = ($prices[$assetId] ?? collect())
                    ->mapWithKeys(fn (PriceHistory $price) => [$price->date->toDateString() => $price->close])
                    ->all();
            }
        }

        return [
            'quantities' => $quantities,
            'priceSeries' => array_filter($priceSeries, fn (array $series): bool => $series !== []),
        ];
    }
}
