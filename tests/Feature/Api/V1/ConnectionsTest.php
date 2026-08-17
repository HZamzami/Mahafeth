<?php

namespace Tests\Feature\Api\V1;

use App\Actions\CreateManualAccount;
use App\Actions\SyncConnection;
use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_manual_and_demo_accounts(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $response = $this->getJson('/api/v1/connections');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.your_accounts'));
        $response->assertJsonPath('data.your_accounts.0.name', 'My Sahm');
    }

    public function test_manual_account_can_be_created(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/connections/manual', [
            'name' => 'My Brokerage',
            'type' => 'brokerage',
            'currency' => 'SAR',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('accounts', ['name' => 'My Brokerage']);
    }

    public function test_consent_screen_returns_scopes_and_ttl(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $institution = Institution::factory()->create();

        $response = $this->getJson("/api/v1/connections/{$institution->slug}/consent");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['institution', 'scopes', 'ttl_days']]);
    }

    public function test_consent_screen_404s_for_import_only_institutions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $institution = Institution::factory()->import()->create();

        $this->getJson("/api/v1/connections/{$institution->slug}/consent")->assertNotFound();
    }

    public function test_approving_consent_creates_a_connection_and_queues_analysis(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        $response = $this->postJson("/api/v1/connections/{$institution->slug}/consent");

        $response->assertCreated();
        $this->assertDatabaseHas('connections', ['user_id' => $user->id, 'institution_id' => $institution->id]);
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'institution_id' => $institution->id]);
    }

    public function test_sync_rejects_a_manual_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $this->postJson("/api/v1/connections/{$account->connection->id}/sync")->assertStatus(422);
    }

    public function test_sync_404s_for_a_connection_the_user_does_not_own(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $other->id]);

        $this->postJson("/api/v1/connections/{$connection->id}/sync")->assertNotFound();
    }

    public function test_destroy_deletes_a_manual_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');
        $connectionId = $account->connection->id;

        $this->deleteJson("/api/v1/connections/{$connectionId}")->assertOk();

        $this->assertDatabaseMissing('connections', ['id' => $connectionId]);
    }

    public function test_destroy_soft_disconnects_a_demo_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->create(['user_id' => $user->id, 'institution_id' => $institution->id]);

        $this->deleteJson("/api/v1/connections/{$connection->id}")->assertOk();

        $this->assertDatabaseHas('connections', ['id' => $connection->id, 'status' => ConnectionStatus::Disconnected->value]);
    }

    public function test_account_show_returns_holdings_and_transactions(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $response = $this->getJson("/api/v1/connections/accounts/{$account->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'My Sahm');
        $response->assertJsonStructure(['data' => ['rows', 'totalValue', 'transactions']]);
    }

    public function test_account_show_404s_for_an_account_the_user_does_not_own(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = User::factory()->create();
        $account = app(CreateManualAccount::class)->handle($other, 'Other', AccountType::Brokerage, 'SAR');

        $this->getJson("/api/v1/connections/accounts/{$account->id}")->assertNotFound();
    }

    public function test_a_deposit_transaction_can_be_recorded_on_a_manual_account(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $response = $this->postJson("/api/v1/connections/accounts/{$account->id}/transactions", [
            'type' => 'deposit',
            'currency' => 'SAR',
            'amount' => 5000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('transactions', ['account_id' => $account->id, 'amount' => 5000]);
    }

    public function test_recording_a_transaction_is_forbidden_on_a_demo_account(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create(['user_id' => $user->id, 'institution_id' => $institution->id]);
        app(SyncConnection::class)->handle($connection);
        $account = $connection->accounts()->first();

        $this->postJson("/api/v1/connections/accounts/{$account->id}/transactions", [
            'type' => 'deposit',
            'currency' => 'SAR',
            'amount' => 5000,
        ])->assertStatus(403);
    }

    public function test_a_transaction_can_be_deleted(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $this->postJson("/api/v1/connections/accounts/{$account->id}/transactions", [
            'type' => 'deposit',
            'currency' => 'SAR',
            'amount' => 5000,
        ]);

        $transactionId = $account->transactions()->first()->id;

        $this->deleteJson("/api/v1/connections/accounts/{$account->id}/transactions/{$transactionId}")->assertOk();

        $this->assertDatabaseMissing('transactions', ['id' => $transactionId]);
    }

    public function test_a_csv_statement_can_be_imported(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = app(CreateManualAccount::class)->handle($user, 'My Sahm', AccountType::Brokerage, 'SAR');

        $file = UploadedFile::fake()->createWithContent('holdings.csv', "symbol,quantity,avg_cost\n2222.SR,800,8.10");

        $response = $this->postJson("/api/v1/connections/accounts/{$account->id}/import", [
            'statement' => $file,
        ]);

        $response->assertCreated();
        $this->assertSame(1, $account->holdings()->count());
    }

    public function test_instrument_search_returns_catalog_and_market_matches(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/connections/instruments/search?query=aramco&currency=SAR');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['catalog', 'market']]);
    }
}
