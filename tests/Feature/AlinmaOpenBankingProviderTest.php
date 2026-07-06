<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Services\OpenBanking\AlinmaOpenBankingProvider;
use App\Services\OpenBanking\FakeOpenBankingProvider;
use App\Services\OpenBanking\OpenBankingProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlinmaOpenBankingProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.alinma' => [
            'base_url' => 'https://sandbox.alinma.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'cert_path' => null,
            'key_path' => null,
        ]]);

        Cache::flush();
    }

    private function institution(): Institution
    {
        return Institution::factory()->create(['slug' => 'alinma-bank', 'provider' => 'alinma_ais']);
    }

    public function test_accounts_and_balances_map_to_the_provider_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'sandbox.alinma.test/oauth2/token' => Http::response(['access_token' => 'token-123']),
            'sandbox.alinma.test/open-banking/v1/accounts' => Http::response([
                'data' => ['accounts' => [
                    ['accountId' => 'ACC-1', 'nickname' => 'Current Account', 'accountType' => 'CurrentAccount', 'currency' => 'SAR'],
                ]],
            ]),
            'sandbox.alinma.test/open-banking/v1/accounts/ACC-1/balances' => Http::response([
                'data' => ['balances' => [['amount' => '185000.00', 'currency' => 'SAR']]],
            ]),
        ]);

        $provider = app(AlinmaOpenBankingProvider::class);
        $institution = $this->institution();

        $accounts = $provider->fetchAccounts($institution);

        $this->assertSame([
            ['external_id' => 'ACC-1', 'name' => 'Current Account', 'type' => 'cash', 'currency' => 'SAR'],
        ], $accounts);

        $holdings = $provider->fetchHoldings($institution, 'ACC-1');

        $this->assertCount(1, $holdings);
        $this->assertSame('CASH-SAR', $holdings[0]['asset']['symbol']);
        $this->assertSame('compliant', $holdings[0]['asset']['shariah_status']);
        $this->assertEqualsWithDelta(185000.0, $holdings[0]['quantity'], 1e-9);

        Http::assertSent(fn ($request) => $request->url() !== 'https://sandbox.alinma.test/oauth2/token'
            || $request['grant_type'] === 'client_credentials');
    }

    public function test_transactions_map_credit_and_debit_indicators(): void
    {
        Http::fake([
            'sandbox.alinma.test/oauth2/token' => Http::response(['access_token' => 'token-123']),
            'sandbox.alinma.test/open-banking/v1/accounts/ACC-1/transactions' => Http::response([
                'data' => ['transactions' => [
                    ['amount' => '5000.00', 'creditDebitIndicator' => 'Credit', 'bookingDateTime' => '2026-06-01T09:00:00Z'],
                    ['amount' => '1200.00', 'creditDebitIndicator' => 'Debit', 'bookingDateTime' => '2026-06-15T09:00:00Z'],
                ]],
            ]),
        ]);

        $transactions = app(AlinmaOpenBankingProvider::class)->fetchTransactions($this->institution(), 'ACC-1');

        $this->assertSame('deposit', $transactions[0]['type']);
        $this->assertSame('withdrawal', $transactions[1]['type']);
        $this->assertEqualsWithDelta(5000.0, $transactions[0]['amount'], 1e-9);
    }

    public function test_api_failures_fall_back_to_the_simulated_provider(): void
    {
        Http::fake(['sandbox.alinma.test/*' => Http::response(status: 500)]);

        $institution = $this->institution();
        $provider = app(AlinmaOpenBankingProvider::class);

        $expected = app(FakeOpenBankingProvider::class)->fetchAccounts($institution);

        $this->assertSame($expected, $provider->fetchAccounts($institution));
        $this->assertNotEmpty($provider->fetchAccounts($institution));
    }

    public function test_the_manager_resolves_alinma_only_when_credentials_are_configured(): void
    {
        $institution = $this->institution();
        $manager = new OpenBankingProviderManager;

        $this->assertInstanceOf(AlinmaOpenBankingProvider::class, $manager->forInstitution($institution));

        config(['services.alinma.client_id' => null]);

        $this->assertInstanceOf(FakeOpenBankingProvider::class, $manager->forInstitution($institution));
    }

    public function test_the_manager_resolves_the_simulated_provider_for_fake_institutions(): void
    {
        $institution = Institution::factory()->create(['slug' => 'derayah', 'provider' => 'fake']);

        $this->assertInstanceOf(
            FakeOpenBankingProvider::class,
            (new OpenBankingProviderManager)->forInstitution($institution),
        );
    }
}
