<?php

namespace App\Actions;

use App\Enums\ConnectionStatus;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\OpenBanking\AssetCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Writes statement-imported holdings into the portfolio for institutions
 * without API access (e.g. Alinma Capital brokerage). Each import replaces
 * the previous statement's holdings, then prices are synced so the
 * analytics pipeline can value the positions.
 */
class ImportHoldings
{
    public function __construct(
        private AssetCatalog $assetCatalog,
        private SyncPrices $syncPrices,
    ) {}

    /**
     * @param  list<array{symbol: string, quantity: float, avg_cost: float}>  $rows
     */
    public function handle(User $user, Institution $institution, array $rows): Connection
    {
        $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);
        $symbols = [];

        DB::transaction(function () use ($connection, $institution, $rows, &$symbols): void {
            $account = $connection->accounts()->updateOrCreate(
                ['external_id' => 'IMP-'.strtoupper($institution->slug)],
                [
                    'name' => __(':institution Portfolio (imported)', ['institution' => $institution->name]),
                    'type' => 'brokerage',
                    'currency' => 'SAR',
                ],
            );

            $account->holdings()->delete();

            foreach ($rows as $row) {
                $asset = Asset::updateOrCreate(
                    ['symbol' => $row['symbol']],
                    $this->assetCatalog->metadata($row['symbol']),
                );

                $account->holdings()->create([
                    'asset_id' => $asset->id,
                    'quantity' => $row['quantity'],
                    'avg_cost' => $row['avg_cost'],
                ]);

                $symbols[] = $asset->symbol;
            }

            $connection->update([
                'status' => ConnectionStatus::Connected,
                'source' => 'import',
                'last_synced_at' => now(),
            ]);
        });

        $this->syncPrices->handle(array_values(array_unique($symbols)));

        return $connection;
    }
}
