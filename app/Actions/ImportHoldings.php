<?php

namespace App\Actions;

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Markets\AssetResolver;
use App\Services\OpenBanking\AssetCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Writes statement-imported holdings into the portfolio, then syncs prices
 * so the analytics pipeline can value the positions. Two entry points:
 * the institution flow (used by the demo seeder) replaces the account's
 * holdings wholesale; the account flow (user-owned manual accounts) appends
 * each row as an opening transaction, so an upload composes with hand-entered
 * positions on one shared ledger.
 */
class ImportHoldings
{
    public function __construct(
        private AssetCatalog $assetCatalog,
        private AssetResolver $assetResolver,
        private SyncPrices $syncPrices,
        private RebuildAccountHoldings $rebuildAccountHoldings,
    ) {}

    /**
     * Replace an institution account's holdings from a fresh statement.
     *
     * @param  list<array{symbol: string, quantity: float, avg_cost: float}>  $rows
     */
    public function handle(User $user, Institution $institution, array $rows): Connection
    {
        $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);

        $symbols = DB::transaction(function () use ($connection, $institution, $rows): array {
            $account = $connection->accounts()->updateOrCreate(
                ['external_id' => 'IMP-'.strtoupper($institution->slug)],
                [
                    'name' => __(':institution Portfolio (imported)', ['institution' => $institution->name]),
                    'type' => 'brokerage',
                    'currency' => 'SAR',
                ],
            );

            $account->holdings()->delete();
            $symbols = $this->writeRows($account, $rows);

            $connection->update([
                'status' => ConnectionStatus::Connected,
                'source' => 'import',
                'last_synced_at' => now(),
            ]);

            return $symbols;
        });

        $this->syncPrices->handle($symbols);

        return $connection;
    }

    /**
     * Append statement rows to a user-owned account as opening transactions —
     * a Buy for each security position, a Deposit for each cash balance — then
     * rederive the affected holdings so CSV and hand-entry share one ledger.
     *
     * @param  list<array{symbol: string, quantity: float, avg_cost: float}>  $rows
     */
    public function intoAccount(Account $account, array $rows): void
    {
        /** @var array<int, Asset> $assets */
        $assets = [];
        $seedPrices = [];

        DB::transaction(function () use ($account, $rows, &$assets, &$seedPrices): void {
            foreach ($rows as $row) {
                $asset = $this->assetResolver->resolve($row['symbol']);
                $quantity = (float) $row['quantity'];
                $price = (float) ($row['avg_cost'] ?? 0);

                if ($asset->asset_class === AssetClass::Cash) {
                    $account->transactions()->create([
                        'asset_id' => $asset->id,
                        'type' => TransactionType::Deposit,
                        'quantity' => $quantity,
                        'price' => 1.0,
                        'amount' => $quantity,
                        'executed_at' => now(),
                    ]);
                    $seedPrices[$asset->symbol] = 1.0;
                } else {
                    $account->transactions()->create([
                        'asset_id' => $asset->id,
                        'type' => TransactionType::Buy,
                        'quantity' => $quantity,
                        'price' => $price,
                        'amount' => $quantity * $price,
                        'executed_at' => now(),
                    ]);
                    $seedPrices[$asset->symbol] = $price > 0 ? $price : 1.0;
                }

                $assets[$asset->id] = $asset;
            }

            $account->connection->update(['last_synced_at' => now()]);
        });

        $this->syncPrices->handle(array_keys($seedPrices), $seedPrices);

        foreach ($assets as $asset) {
            $this->rebuildAccountHoldings->forAsset($account, $asset);
        }
    }

    /**
     * Upsert each row's asset and holding onto the account.
     *
     * @param  list<array{symbol: string, quantity: float, avg_cost: float}>  $rows
     * @return list<string>
     */
    private function writeRows(Account $account, array $rows): array
    {
        $symbols = [];

        foreach ($rows as $row) {
            $asset = Asset::updateOrCreate(
                ['symbol' => $row['symbol']],
                $this->assetCatalog->metadata($row['symbol']),
            );

            $account->holdings()->updateOrCreate(
                ['asset_id' => $asset->id],
                ['quantity' => $row['quantity'], 'avg_cost' => $row['avg_cost']],
            );

            $symbols[] = $asset->symbol;
        }

        return array_values(array_unique($symbols));
    }
}
