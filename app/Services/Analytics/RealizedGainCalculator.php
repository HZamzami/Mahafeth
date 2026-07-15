<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fx\FxRateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Realized gains and losses locked in by sells, replayed from the transaction
 * ledger with the same average-cost method that derives holdings. Each sell
 * books `quantity × (sale price − running average cost)`; buys only move the
 * running cost, never the realized total. Cost bases are tracked per account
 * so the same symbol held in two accounts keeps independent lots. Native-
 * currency figures convert at current FX rates (no rate history exists), the
 * same approximation the rest of the analytics engine accepts.
 */
class RealizedGainCalculator
{
    public function __construct(private FxRateService $fxRateService) {}

    /**
     * Realized P/L for one asset across the user's connected accounts, in the
     * asset's native currency.
     */
    public function forAsset(User $user, Asset $asset): float
    {
        $transactions = $this->tradeQuery($user)->where('asset_id', $asset->id)->get();

        return round($this->realizedForAsset($transactions), 4);
    }

    /**
     * Portfolio-wide realized P/L across every connected account, converted to
     * the base currency.
     */
    public function forUser(User $user): float
    {
        $transactions = $this->tradeQuery($user)->with('asset')->get();

        $total = 0.0;

        foreach ($transactions->groupBy('asset_id') as $assetTransactions) {
            $currency = $assetTransactions->first()->asset->currency ?? config('mahafeth.base_currency');
            $total += $this->realizedForAsset($assetTransactions) * $this->fxRateService->rate($currency);
        }

        return round($total, 2);
    }

    /**
     * Sum realized P/L across each account holding the asset, replaying its
     * lots independently.
     *
     * @param  Collection<int, Transaction>  $transactions  all for one asset
     */
    private function realizedForAsset(Collection $transactions): float
    {
        $realized = 0.0;

        foreach ($transactions->groupBy('account_id') as $accountTransactions) {
            $realized += $this->replay($accountTransactions);
        }

        return $realized;
    }

    /**
     * Chronologically replay one account's lots for a single asset.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function replay(Collection $transactions): float
    {
        $quantity = 0.0;
        $avgCost = 0.0;
        $realized = 0.0;

        $ordered = $transactions
            ->sortBy(fn (Transaction $transaction): array => [$transaction->executed_at->getTimestamp(), $transaction->id])
            ->values();

        foreach ($ordered as $transaction) {
            if ($transaction->type === TransactionType::Buy) {
                $newQuantity = $quantity + $transaction->quantity;
                $avgCost = $newQuantity > 0
                    ? (($quantity * $avgCost) + ($transaction->quantity * $transaction->price)) / $newQuantity
                    : 0.0;
                $quantity = $newQuantity;
            } elseif ($transaction->type === TransactionType::Sell) {
                $realized += $transaction->quantity * ($transaction->price - $avgCost);
                $quantity -= $transaction->quantity;
            }
        }

        return $realized;
    }

    /**
     * @return Builder<Transaction>
     */
    private function tradeQuery(User $user): Builder
    {
        return Transaction::query()
            ->whereIn('type', [TransactionType::Buy, TransactionType::Sell])
            ->whereHas('account.connection', fn (Builder $query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected));
    }
}
