<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Models\FxRate;
use App\Models\PriceHistory;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How current the numbers behind a user's dashboard are: the newest price
 * row across their held assets and the last FX fetch. Aggregated numbers
 * only earn trust when the app is honest about their age.
 */
class DataFreshness
{
    /**
     * @return array{
     *     prices_as_of: ?Carbon,
     *     fx_fetched_at: ?Carbon,
     *     last_synced_at: ?Carbon,
     *     stale_prices: bool,
     *     stale_fx: bool
     * }|null null when the user has no holdings to be fresh about
     */
    public function forUser(User $user): ?array
    {
        $pricesAsOf = PriceHistory::whereHas(
            'asset.holdings.account.connection',
            fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected),
        )->max('date');

        if ($pricesAsOf === null) {
            return null;
        }

        $pricesAsOf = Carbon::parse($pricesAsOf);
        $fxFetchedAt = FxRate::max('fetched_at');
        $fxFetchedAt = $fxFetchedAt !== null ? Carbon::parse($fxFetchedAt) : null;
        $lastSyncedAt = $user->connections()->max('last_synced_at');

        // Prices tolerate a market-closure gap (Saudi Fri/Sat plus US
        // Sat/Sun); FX rates are fetched daily and go stale much faster.
        $priceStaleDays = (int) config('mahafeth.freshness.price_stale_days', 4);
        $fxStaleHours = (int) config('mahafeth.freshness.fx_stale_hours', 48);

        return [
            'prices_as_of' => $pricesAsOf,
            'fx_fetched_at' => $fxFetchedAt,
            'last_synced_at' => $lastSyncedAt !== null ? Carbon::parse($lastSyncedAt) : null,
            'stale_prices' => $pricesAsOf->lt(now()->subDays($priceStaleDays)),
            'stale_fx' => $fxFetchedAt === null || $fxFetchedAt->lt(now()->subHours($fxStaleHours)),
        ];
    }
}
