<?php

namespace App\Actions;

use App\Enums\AssetClass;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Derives an account's holding for one asset by replaying its transactions
 * chronologically. Securities use average-cost accounting; cash nets
 * deposits against withdrawals. A position that closes out (quantity at or
 * below zero) drops its holding row entirely.
 */
class RebuildAccountHoldings
{
    public function forAsset(Account $account, Asset $asset): void
    {
        $transactions = $account->transactions()
            ->where('asset_id', $asset->id)
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

        [$quantity, $avgCost] = $asset->asset_class === AssetClass::Cash
            ? $this->replayCash($transactions)
            : $this->replaySecurity($transactions);

        if ($quantity <= 1e-9) {
            $account->holdings()->where('asset_id', $asset->id)->delete();

            return;
        }

        $account->holdings()->updateOrCreate(
            ['asset_id' => $asset->id],
            ['quantity' => $quantity, 'avg_cost' => $avgCost],
        );
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{0: float, 1: float} [quantity, average cost]
     */
    private function replaySecurity($transactions): array
    {
        $quantity = 0.0;
        $avgCost = 0.0;

        foreach ($transactions as $transaction) {
            if ($transaction->type === TransactionType::Buy) {
                $newQuantity = $quantity + $transaction->quantity;
                $avgCost = $newQuantity > 0
                    ? (($quantity * $avgCost) + ($transaction->quantity * $transaction->price)) / $newQuantity
                    : 0.0;
                $quantity = $newQuantity;
            } elseif ($transaction->type === TransactionType::Sell) {
                $quantity -= $transaction->quantity;
            }
        }

        return [$quantity, $avgCost];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{0: float, 1: float} [quantity, average cost]
     */
    private function replayCash($transactions): array
    {
        $quantity = 0.0;

        foreach ($transactions as $transaction) {
            if ($transaction->type === TransactionType::Deposit) {
                $quantity += $transaction->amount;
            } elseif ($transaction->type === TransactionType::Withdrawal) {
                $quantity -= $transaction->amount;
            }
        }

        return [$quantity, 1.0];
    }
}
