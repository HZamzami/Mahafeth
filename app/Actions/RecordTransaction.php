<?php

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Services\Markets\AssetResolver;

/**
 * Records a dated transaction on a manual account and rederives the affected
 * holding from the ledger. Buy/Sell move a security position (average-cost);
 * Deposit/Withdraw move cash. Buys and sells never touch cash — cash is
 * managed with its own deposits and withdrawals.
 */
class RecordTransaction
{
    public function __construct(
        private AssetResolver $assetResolver,
        private SyncPrices $syncPrices,
        private RebuildAccountHoldings $rebuildAccountHoldings,
    ) {}

    /**
     * @param  array{symbol?: string, meta?: ?array, quantity?: float|string, price?: float|string, currency?: string, amount?: float|string, executed_at: mixed}  $data
     */
    public function handle(Account $account, TransactionType $type, array $data): void
    {
        $asset = match ($type) {
            TransactionType::Buy, TransactionType::Sell => $this->recordTrade($account, $type, $data),
            default => $this->recordCashFlow($account, $type, $data),
        };

        $account->connection->update(['last_synced_at' => now()]);
        $this->rebuildAccountHoldings->forAsset($account, $asset);
    }

    /**
     * @param  array{symbol?: string, meta?: ?array, quantity?: float|string, price?: float|string, executed_at: mixed}  $data
     */
    private function recordTrade(Account $account, TransactionType $type, array $data): Asset
    {
        $asset = $this->assetResolver->resolve($data['symbol'], $data['meta'] ?? null);
        $quantity = (float) $data['quantity'];
        $price = (float) $data['price'];

        $account->transactions()->create([
            'asset_id' => $asset->id,
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'amount' => $quantity * $price,
            'executed_at' => $data['executed_at'],
        ]);

        $this->syncPrices->handle([$asset->symbol], [$asset->symbol => $price]);

        return $asset;
    }

    /**
     * @param  array{currency?: string, amount?: float|string, executed_at: mixed}  $data
     */
    private function recordCashFlow(Account $account, TransactionType $type, array $data): Asset
    {
        $asset = $this->assetResolver->resolve('CASH-'.$data['currency']);
        $amount = (float) $data['amount'];

        $account->transactions()->create([
            'asset_id' => $asset->id,
            'type' => $type,
            'quantity' => $amount,
            'price' => 1.0,
            'amount' => $amount,
            'executed_at' => $data['executed_at'],
        ]);

        $this->syncPrices->handle([$asset->symbol], [$asset->symbol => 1.0]);

        return $asset;
    }
}
