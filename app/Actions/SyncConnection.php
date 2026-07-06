<?php

namespace App\Actions;

use App\Contracts\OpenBankingProvider;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PriceHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pulls accounts, holdings, transactions, and price history for a connection
 * from the Open Banking provider into the local database.
 */
class SyncConnection
{
    private const PRICE_HISTORY_YEARS = 3;

    public function __construct(private OpenBankingProvider $provider) {}

    public function handle(Connection $connection): void
    {
        $institution = $connection->institution;
        $symbols = [];

        DB::transaction(function () use ($connection, $institution, &$symbols): void {
            foreach ($this->provider->fetchAccounts($institution) as $accountData) {
                $account = $connection->accounts()->updateOrCreate(
                    ['external_id' => $accountData['external_id']],
                    [
                        'name' => $accountData['name'],
                        'type' => $accountData['type'],
                        'currency' => $accountData['currency'],
                    ],
                );

                $symbols = array_merge($symbols, $this->syncHoldings($institution, $account));
                $this->syncTransactions($institution, $account);
            }

            foreach ($this->provider->benchmarks() as $benchmark) {
                $this->upsertAsset($benchmark, isBenchmark: true);
                $symbols[] = $benchmark['symbol'];
            }

            $connection->update([
                'status' => ConnectionStatus::Connected,
                'last_synced_at' => now(),
            ]);
        });

        $this->syncPrices(array_values(array_unique($symbols)));
    }

    /**
     * @return list<string> the symbols synced into the account
     */
    private function syncHoldings(Institution $institution, Account $account): array
    {
        $symbols = [];

        foreach ($this->provider->fetchHoldings($institution, $account->external_id) as $holdingData) {
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

    private function syncTransactions(Institution $institution, Account $account): void
    {
        if ($account->transactions()->exists()) {
            return;
        }

        foreach ($this->provider->fetchTransactions($institution, $account->external_id) as $transactionData) {
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
     * @param  list<string>  $symbols
     */
    private function syncPrices(array $symbols): void
    {
        $from = Carbon::now()->subYears(self::PRICE_HISTORY_YEARS)->startOfDay();
        $to = Carbon::now()->startOfDay();

        $assetIds = Asset::whereIn('symbol', $symbols)->pluck('id', 'symbol');
        $series = $this->provider->fetchPrices($symbols, $from, $to);

        foreach ($series as $symbol => $prices) {
            $rows = [];

            foreach ($prices as $date => $close) {
                $rows[] = [
                    'asset_id' => $assetIds[$symbol],
                    'date' => $date,
                    'close' => $close,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                PriceHistory::upsert($chunk, ['asset_id', 'date'], ['close']);
            }
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
