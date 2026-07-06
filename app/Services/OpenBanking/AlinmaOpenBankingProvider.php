<?php

namespace App\Services\OpenBanking;

use App\Contracts\OpenBankingProvider;
use App\Models\Institution;
use App\Services\Prices\SimulatedPriceProvider;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Account Information Services provider against the Alinma Open Banking
 * sandbox (KSA Open Banking framework). AIS covers cash accounts, balances,
 * and transactions; brokerage holdings are out of scope until SAMA's
 * investment-account APIs land, so each cash account is represented as a
 * single CASH-SAR holding valued at its balance.
 *
 * Every fetch falls back to the simulated provider on any API failure so a
 * live demo can never break on the sandbox.
 */
class AlinmaOpenBankingProvider implements OpenBankingProvider
{
    public function __construct(
        private FakeOpenBankingProvider $fallback,
        private AssetCatalog $assetCatalog,
        private SimulatedPriceProvider $priceProvider,
    ) {}

    public function fetchAccounts(Institution $institution): array
    {
        try {
            $accounts = $this->client()
                ->get('/open-banking/v1/accounts')
                ->throw()
                ->json('data.accounts', []);

            return array_map(fn (array $account): array => [
                'external_id' => $account['accountId'],
                'name' => $account['nickname'] ?? $account['accountType'] ?? __('Alinma Account'),
                'type' => 'cash',
                'currency' => $account['currency'] ?? 'SAR',
            ], $accounts);
        } catch (\Throwable $exception) {
            $this->warn(__METHOD__, $exception);

            return $this->fallback->fetchAccounts($institution);
        }
    }

    public function fetchHoldings(Institution $institution, string $accountExternalId): array
    {
        try {
            $balance = (float) $this->client()
                ->get("/open-banking/v1/accounts/{$accountExternalId}/balances")
                ->throw()
                ->json('data.balances.0.amount', 0.0);

            return [[
                'asset' => $this->assetCatalog->metadata('CASH-SAR'),
                'quantity' => $balance,
                'avg_cost' => 1.0,
            ]];
        } catch (\Throwable $exception) {
            $this->warn(__METHOD__, $exception);

            return $this->fallback->fetchHoldings($institution, $accountExternalId);
        }
    }

    public function fetchTransactions(Institution $institution, string $accountExternalId): array
    {
        try {
            $transactions = $this->client()
                ->get("/open-banking/v1/accounts/{$accountExternalId}/transactions")
                ->throw()
                ->json('data.transactions', []);

            return array_map(fn (array $transaction): array => [
                'symbol' => 'CASH-SAR',
                'type' => ($transaction['creditDebitIndicator'] ?? 'Credit') === 'Credit' ? 'deposit' : 'withdrawal',
                'quantity' => (float) $transaction['amount'],
                'price' => 1.0,
                'amount' => (float) $transaction['amount'],
                'executed_at' => Carbon::parse($transaction['bookingDateTime'] ?? now()),
            ], $transactions);
        } catch (\Throwable $exception) {
            $this->warn(__METHOD__, $exception);

            return $this->fallback->fetchTransactions($institution, $accountExternalId);
        }
    }

    public function fetchPrices(array $symbols, CarbonInterface $from, CarbonInterface $to): array
    {
        // The sandbox exposes no market data; cash is flat at 1.0 and any
        // other symbol comes from the shared simulated series.
        return $this->priceProvider->fetchDailyCloses($symbols, $from, $to);
    }

    public function benchmarks(): array
    {
        return $this->fallback->benchmarks();
    }

    /**
     * Authenticated HTTP client for the sandbox (client-credentials grant,
     * with mTLS certificates when configured).
     */
    private function client(): PendingRequest
    {
        $config = config('services.alinma');

        $request = Http::baseUrl($config['base_url'])
            ->timeout(15)
            ->withToken($this->accessToken());

        if (! empty($config['cert_path']) && ! empty($config['key_path'])) {
            $request = $request->withOptions([
                'cert' => $config['cert_path'],
                'ssl_key' => $config['key_path'],
            ]);
        }

        return $request;
    }

    private function accessToken(): string
    {
        $config = config('services.alinma');

        return cache()->remember('alinma-ob-token', now()->addMinutes(10), function () use ($config): string {
            return (string) Http::baseUrl($config['base_url'])
                ->asForm()
                ->post('/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'scope' => 'accounts',
                ])
                ->throw()
                ->json('access_token');
        });
    }

    private function warn(string $method, \Throwable $exception): void
    {
        Log::warning('Alinma Open Banking call failed, using simulated data.', [
            'method' => $method,
            'error' => $exception->getMessage(),
        ]);
    }
}
