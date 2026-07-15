<?php

namespace Tests\Feature;

use App\Actions\CreateManualAccount;
use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ConnectionsAccountTest extends TestCase
{
    use RefreshDatabase;

    private function manualAccount(User $user): Account
    {
        return app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');
    }

    private function holdingQuantity(Account $account, string $symbol): ?float
    {
        $holding = $account->holdings()->whereHas('asset', fn ($query) => $query->where('symbol', $symbol))->first();

        return $holding?->quantity;
    }

    public function test_guests_and_foreign_users_cannot_view_an_account(): void
    {
        $account = $this->manualAccount(User::factory()->create());

        $this->get(route('connections.account', $account))->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->get(route('connections.account', $account))->assertNotFound();
    }

    public function test_the_owner_sees_the_account_detail(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);

        $this->actingAs($user)
            ->get(route('connections.account', $account))
            ->assertOk()
            ->assertSee('My Sahm')
            ->assertSee(__('Record transaction'));
    }

    public function test_a_buy_derives_a_holding_with_cost_basis(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])
            ->set('txnType', 'buy')
            ->call('selectInstrument', 'AAPL', 'Apple Inc.')
            ->set('txnQuantity', '25')
            ->set('txnPrice', '130')
            ->set('txnDate', '2026-01-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $holding = $account->holdings()->with('asset')->firstOrFail();
        $this->assertSame('AAPL', $holding->asset->symbol);
        $this->assertEqualsWithDelta(25.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(130.0, $holding->avg_cost, 1e-9);
        $this->assertNotNull($user->latestSnapshot());
    }

    public function test_a_second_buy_recomputes_the_average_cost(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account]);

        foreach ([['25', '130'], ['25', '150']] as [$quantity, $price]) {
            $component->set('txnType', 'buy')
                ->call('selectInstrument', 'AAPL', 'Apple Inc.')
                ->set('txnQuantity', $quantity)
                ->set('txnPrice', $price)
                ->set('txnDate', '2026-01-05')
                ->call('recordTransaction')
                ->assertHasNoErrors();
        }

        $holding = $account->holdings()->firstOrFail();
        $this->assertEqualsWithDelta(50.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(140.0, $holding->avg_cost, 1e-9);
    }

    public function test_a_sell_reduces_quantity_and_closes_the_position_at_zero(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account])
            ->set('txnType', 'buy')
            ->call('selectInstrument', 'AAPL', 'Apple Inc.')
            ->set('txnQuantity', '25')
            ->set('txnPrice', '130')
            ->set('txnDate', '2026-01-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $component->set('txnType', 'sell')
            ->call('selectInstrument', 'AAPL', 'Apple Inc.')
            ->set('txnQuantity', '10')
            ->set('txnPrice', '160')
            ->set('txnDate', '2026-02-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(15.0, $this->holdingQuantity($account, 'AAPL'), 1e-9);

        $component->set('txnType', 'sell')
            ->call('selectInstrument', 'AAPL', 'Apple Inc.')
            ->set('txnQuantity', '15')
            ->set('txnPrice', '170')
            ->set('txnDate', '2026-03-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $this->assertNull($this->holdingQuantity($account, 'AAPL'));
    }

    public function test_deposits_and_withdrawals_drive_cash(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account])
            ->set('txnType', 'deposit')
            ->set('txnCurrency', 'SAR')
            ->set('txnAmount', '50000')
            ->set('txnDate', '2026-01-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(50000.0, $this->holdingQuantity($account, 'CASH-SAR'), 1e-9);

        $component->set('txnType', 'withdrawal')
            ->set('txnCurrency', 'SAR')
            ->set('txnAmount', '20000')
            ->set('txnDate', '2026-02-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(30000.0, $this->holdingQuantity($account, 'CASH-SAR'), 1e-9);
    }

    public function test_any_listed_symbol_can_be_added(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])
            ->set('txnType', 'buy')
            ->call('selectInstrument', 'TSLA', 'Tesla, Inc.', [
                'symbol' => 'TSLA',
                'name' => 'Tesla, Inc.',
                'exchange' => 'NASDAQ',
                'country' => 'United States',
                'currency' => 'USD',
                'type' => 'Common Stock',
            ])
            ->set('txnQuantity', '10')
            ->set('txnPrice', '240')
            ->set('txnDate', '2026-01-05')
            ->call('recordTransaction')
            ->assertHasNoErrors();

        $holding = $account->holdings()->with('asset')->firstOrFail();
        $this->assertSame('TSLA', $holding->asset->symbol);
        $this->assertGreaterThanOrEqual(2, $holding->asset->priceHistories()->count());
    }

    public function test_deleting_a_transaction_rebuilds_the_holding(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account]);

        foreach ([['25', '130'], ['25', '150']] as [$quantity, $price]) {
            $component->set('txnType', 'buy')
                ->call('selectInstrument', 'AAPL', 'Apple Inc.')
                ->set('txnQuantity', $quantity)
                ->set('txnPrice', $price)
                ->set('txnDate', '2026-01-05')
                ->call('recordTransaction')
                ->assertHasNoErrors();
        }

        $second = $account->transactions()->orderByDesc('id')->firstOrFail();
        $component->call('deleteTransaction', $second->id);

        $holding = $account->holdings()->firstOrFail();
        $this->assertEqualsWithDelta(25.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(130.0, $holding->avg_cost, 1e-9);
    }

    public function test_a_csv_import_adds_opening_buys(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account])
            ->set('txnType', 'buy')
            ->call('selectInstrument', 'AAPL', 'Apple Inc.')
            ->set('txnQuantity', '25')
            ->set('txnPrice', '130')
            ->set('txnDate', '2026-01-05')
            ->call('recordTransaction');

        $component->set('statement', UploadedFile::fake()->createWithContent('holdings.csv', "symbol,quantity,avg_cost\n2222.SR,800,8.10"))
            ->call('importCsv')
            ->assertHasNoErrors();

        $symbols = $account->holdings()->with('asset')->get()->pluck('asset.symbol');
        $this->assertTrue($symbols->contains('AAPL'), 'existing holding was kept');
        $this->assertTrue($symbols->contains('2222.SR'), 'imported holding was added');
        $this->assertEqualsWithDelta(800.0, $this->holdingQuantity($account, '2222.SR'), 1e-9);
    }

    public function test_a_demo_account_is_view_only(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id, 'status' => ConnectionStatus::Connected]);
        $account = Account::factory()->create(['connection_id' => $connection->id]);

        $this->actingAs($user)
            ->get(route('connections.account', $account))
            ->assertOk()
            ->assertSee(__('Demo — sample data'))
            ->assertDontSee(__('Record transaction'));
    }

    public function test_deleting_a_manual_account_removes_it(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])
            ->call('deleteAccount')
            ->assertRedirect(route('connections'));

        $this->assertDatabaseMissing('connections', ['id' => $account->connection->id]);
    }

    public function test_removing_a_demo_account_disconnects_it(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id, 'status' => ConnectionStatus::Connected]);
        $account = Account::factory()->create(['connection_id' => $connection->id]);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])->call('deleteAccount');

        $this->assertSame(ConnectionStatus::Disconnected, $connection->refresh()->status);
    }
}
