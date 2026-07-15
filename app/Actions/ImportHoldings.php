<?php

namespace App\Actions;

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\OpenBanking\AssetCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Writes statement-imported holdings into the portfolio, then syncs prices
 * so the analytics pipeline can value the positions. Two entry points:
 * the institution flow (used by the demo seeder) replaces the account's
 * holdings wholesale; the account flow (user-owned manual accounts) merges,
 * so an upload composes with hand-entered positions.
 */
class ImportHoldings
{
    public function __construct(
        private AssetCatalog $assetCatalog,
        private SyncPrices $syncPrices,
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
     * Merge statement rows into a user-owned account: existing positions are
     * updated, new ones added, nothing removed.
     *
     * @param  list<array{symbol: string, quantity: float, avg_cost: float}>  $rows
     */
    public function intoAccount(Account $account, array $rows): void
    {
        $symbols = DB::transaction(function () use ($account, $rows): array {
            $symbols = $this->writeRows($account, $rows);

            $account->connection->update(['last_synced_at' => now()]);

            return $symbols;
        });

        $this->syncPrices->handle($symbols);
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
