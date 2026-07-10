<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PriceHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeDerayahConnection(): Connection
    {
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        return Connection::factory()->pending()->create([
            'user_id' => User::factory(),
            'institution_id' => $institution->id,
        ]);
    }

    public function test_syncing_a_connection_imports_accounts_holdings_transactions_and_prices(): void
    {
        $connection = $this->makeDerayahConnection();

        app(SyncConnection::class)->handle($connection);

        $connection->refresh();
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertNotNull($connection->last_synced_at);

        $this->assertCount(1, $connection->accounts);

        $account = $connection->accounts->first();
        $this->assertCount(5, $account->holdings);

        // Each of the 5 equities gets two buy lots and two dividends.
        $this->assertCount(20, $account->transactions);

        $apple = Asset::where('symbol', 'AAPL')->first();
        $this->assertNotNull($apple);
        $this->assertSame('Information Technology', $apple->sector);
        $this->assertInstanceOf(ShariahStatus::class, $apple->shariah_status);
        $this->assertGreaterThan(700, $apple->priceHistories()->count());

        $this->assertTrue(Asset::where('symbol', 'SPY')->where('is_benchmark', true)->exists());
        $this->assertTrue(Asset::where('symbol', 'TASI')->where('is_benchmark', true)->exists());
    }

    public function test_syncing_twice_is_idempotent(): void
    {
        $connection = $this->makeDerayahConnection();
        $syncConnection = app(SyncConnection::class);

        $syncConnection->handle($connection);

        $counts = [
            'accounts' => $connection->accounts()->count(),
            'holdings' => $connection->accounts->first()->holdings()->count(),
            'transactions' => $connection->accounts->first()->transactions()->count(),
            'assets' => Asset::count(),
            'prices' => PriceHistory::count(),
        ];

        $syncConnection->handle($connection->refresh());

        $this->assertSame($counts['accounts'], $connection->accounts()->count());
        $this->assertSame($counts['holdings'], $connection->accounts->first()->holdings()->count());
        $this->assertSame($counts['transactions'], $connection->accounts->first()->transactions()->count());
        $this->assertSame($counts['assets'], Asset::count());
        $this->assertSame($counts['prices'], PriceHistory::count());
    }

    public function test_a_resync_removes_price_rows_from_another_provider(): void
    {
        $connection = $this->makeDerayahConnection();
        app(SyncConnection::class)->handle($connection);

        // A leftover close from a different provider on a date the current
        // provider never returns (a weekend) zigzags the return series and
        // inflates every volatility-derived metric.
        $apple = Asset::where('symbol', 'AAPL')->first();
        $saturday = now()->subMonths(6)->next(CarbonInterface::SATURDAY)->toDateString();
        PriceHistory::factory()->create([
            'asset_id' => $apple->id,
            'date' => $saturday,
            'close' => 1.0,
        ]);

        app(SyncConnection::class)->handle($connection->refresh());

        $this->assertFalse($apple->priceHistories()->where('date', $saturday)->exists());
    }

    public function test_syncing_an_institution_unknown_to_the_provider_creates_no_accounts(): void
    {
        $institution = Institution::factory()->create(['slug' => 'unknown-bank']);
        $connection = Connection::factory()->pending()->create(['institution_id' => $institution->id]);

        app(SyncConnection::class)->handle($connection);

        $this->assertCount(0, $connection->accounts);
        $this->assertSame(ConnectionStatus::Connected, $connection->refresh()->status);
    }
}
