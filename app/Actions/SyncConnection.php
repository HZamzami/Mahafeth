<?php

namespace App\Actions;

use App\Contracts\OpenBankingProvider;
use App\Enums\ActivityType;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Services\OpenBanking\OpenBankingProviderManager;
use Illuminate\Support\Facades\DB;

/**
 * Pulls accounts, holdings, transactions, and price history for a connection
 * from the institution's Open Banking provider into the local database.
 */
class SyncConnection
{
    public function __construct(
        private OpenBankingProviderManager $providers,
        private SyncPrices $syncPrices,
    ) {}

    public function handle(Connection $connection): void
    {
        $institution = $connection->institution;
        $provider = $this->providers->forInstitution($institution);
        $symbols = [];
        $holdingsCount = 0;

        DB::transaction(function () use ($connection, $institution, $provider, &$symbols, &$holdingsCount): void {
            foreach ($provider->fetchAccounts($institution) as $accountData) {
                $account = $connection->accounts()->updateOrCreate(
                    ['external_id' => $accountData['external_id']],
                    [
                        'name' => $accountData['name'],
                        'type' => $accountData['type'],
                        'currency' => $accountData['currency'],
                    ],
                );

                $held = $this->syncHoldings($provider, $institution, $account);
                $holdingsCount += count($held);
                $symbols = array_merge($symbols, $held);
                $this->syncTransactions($provider, $institution, $account);
            }

            foreach ($provider->benchmarks() as $benchmark) {
                $this->upsertAsset($benchmark, isBenchmark: true);
                $symbols[] = $benchmark['symbol'];
            }

            $connection->update([
                'status' => ConnectionStatus::Connected,
                'last_synced_at' => now(),
            ]);
        });

        ActivityEvent::record($connection->user, ActivityType::ConnectionSynced, [
            'institution' => $institution->localizedName(),
            'count' => $holdingsCount,
        ]);

        $this->syncPrices->handle(array_values(array_unique($symbols)));
    }

    /**
     * @return list<string> the symbols synced into the account
     */
    private function syncHoldings(OpenBankingProvider $provider, Institution $institution, Account $account): array
    {
        $symbols = [];

        foreach ($provider->fetchHoldings($institution, $account->external_id) as $holdingData) {
            $asset = $this->upsertAsset($holdingData['asset']);

            $account->holdings()->updateOrCreate(
                ['asset_id' => $asset->id],
                [
                    'quantity' => $holdingData['quantity'],
                    'avg_cost' => $holdingData['avg_cost'],
                ],
            );

            $symbols[] = $asset->symbol;
        }

        return $symbols;
    }

    private function syncTransactions(OpenBankingProvider $provider, Institution $institution, Account $account): void
    {
        if ($account->transactions()->exists()) {
            return;
        }

        foreach ($provider->fetchTransactions($institution, $account->external_id) as $transactionData) {
            $account->transactions()->create([
                'asset_id' => $transactionData['symbol'] !== null
                    ? Asset::where('symbol', $transactionData['symbol'])->value('id')
                    : null,
                'type' => $transactionData['type'],
                'quantity' => $transactionData['quantity'],
                'price' => $transactionData['price'],
                'amount' => $transactionData['amount'],
                'executed_at' => $transactionData['executed_at'],
            ]);
        }
    }

    /**
     * @param  array{symbol: string, name: string, name_ar: ?string, asset_class: string, sector: ?string, country: ?string, currency: string, shariah_status?: string}  $metadata
     */
    private function upsertAsset(array $metadata, bool $isBenchmark = false): Asset
    {
        return Asset::updateOrCreate(
            ['symbol' => $metadata['symbol']],
            [
                'name' => $metadata['name'],
                'name_ar' => $metadata['name_ar'],
                'asset_class' => $metadata['asset_class'],
                'sector' => $metadata['sector'],
                'country' => $metadata['country'],
                'currency' => $metadata['currency'],
                'shariah_status' => $metadata['shariah_status'] ?? ShariahStatus::Unknown,
                'is_benchmark' => $isBenchmark,
            ],
        );
    }
}
