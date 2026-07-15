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
            ->assertSee(__('Add to this account'));
    }

    public function test_adding_a_holding_and_cash(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])
            ->set('addSymbol', 'AAPL')
            ->set('addQuantity', '25')
            ->set('addAvgCost', '130')
            ->call('addHolding')
            ->assertHasNoErrors()
            ->set('cashCurrency', 'SAR')
            ->set('cashAmount', '50000')
            ->call('addCash')
            ->assertHasNoErrors();

        $symbols = $account->holdings()->with('asset')->get()->pluck('asset.symbol');
        $this->assertCount(2, $symbols);
        $this->assertTrue($symbols->contains('AAPL'));
        $this->assertTrue($symbols->contains('CASH-SAR'));
        $this->assertNotNull($user->latestSnapshot());
    }

    public function test_an_uncatalogued_symbol_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        Volt::test('connections.account', ['account' => $account])
            ->set('addSymbol', 'ZZZZ')
            ->set('addQuantity', '10')
            ->call('addHolding')
            ->assertHasErrors('addSymbol');

        $this->assertSame(0, $account->holdings()->count());
    }

    public function test_editing_and_removing_a_holding(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account])
            ->set('addSymbol', 'AAPL')
            ->set('addQuantity', '25')
            ->call('addHolding');

        $holding = $account->holdings()->firstOrFail();

        $component->call('startEdit', $holding->id)
            ->set('editQuantity', '40')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(40.0, $holding->refresh()->quantity, 1e-9);

        $component->call('removeHolding', $holding->id);
        $this->assertDatabaseMissing('holdings', ['id' => $holding->id]);
    }

    public function test_a_csv_import_merges_into_the_account(): void
    {
        $user = User::factory()->create();
        $account = $this->manualAccount($user);
        $this->actingAs($user);

        $component = Volt::test('connections.account', ['account' => $account])
            ->set('addSymbol', 'AAPL')
            ->set('addQuantity', '25')
            ->call('addHolding');

        $component->set('statement', UploadedFile::fake()->createWithContent('holdings.csv', "symbol,quantity,avg_cost\n2222.SR,800,8.10"))
            ->call('importCsv')
            ->assertHasNoErrors();

        $symbols = $account->holdings()->with('asset')->get()->pluck('asset.symbol');
        $this->assertTrue($symbols->contains('AAPL'), 'existing holding was kept');
        $this->assertTrue($symbols->contains('2222.SR'), 'imported holding was added');
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
            ->assertDontSee(__('Add to this account'));
    }
}
