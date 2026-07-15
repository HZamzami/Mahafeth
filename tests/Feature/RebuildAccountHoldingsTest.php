<?php

namespace Tests\Feature;

use App\Actions\CreateManualAccount;
use App\Actions\RebuildAccountHoldings;
use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\User;
use App\Services\Markets\AssetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebuildAccountHoldingsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return app(CreateManualAccount::class)->handle(User::factory()->create(), 'Ledger', AccountType::Brokerage, 'SAR');
    }

    private function record(Account $account, Asset $asset, TransactionType $type, float $quantity, float $price, string $date): void
    {
        $account->transactions()->create([
            'asset_id' => $asset->id,
            'type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'amount' => $quantity * $price,
            'executed_at' => $date,
        ]);
    }

    public function test_average_cost_is_weighted_across_buys(): void
    {
        $account = $this->account();
        $asset = app(AssetResolver::class)->resolve('AAPL');

        $this->record($account, $asset, TransactionType::Buy, 25, 130, '2026-01-01');
        $this->record($account, $asset, TransactionType::Buy, 75, 150, '2026-02-01');

        app(RebuildAccountHoldings::class)->forAsset($account, $asset);

        $holding = $account->holdings()->firstOrFail();
        $this->assertEqualsWithDelta(100.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(145.0, $holding->avg_cost, 1e-9);
    }

    public function test_a_sell_leaves_the_average_cost_untouched(): void
    {
        $account = $this->account();
        $asset = app(AssetResolver::class)->resolve('AAPL');

        $this->record($account, $asset, TransactionType::Buy, 100, 140, '2026-01-01');
        $this->record($account, $asset, TransactionType::Sell, 30, 200, '2026-02-01');

        app(RebuildAccountHoldings::class)->forAsset($account, $asset);

        $holding = $account->holdings()->firstOrFail();
        $this->assertEqualsWithDelta(70.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(140.0, $holding->avg_cost, 1e-9);
    }

    public function test_selling_the_whole_position_deletes_the_holding(): void
    {
        $account = $this->account();
        $asset = app(AssetResolver::class)->resolve('AAPL');

        $this->record($account, $asset, TransactionType::Buy, 40, 130, '2026-01-01');
        $this->record($account, $asset, TransactionType::Sell, 40, 160, '2026-02-01');

        app(RebuildAccountHoldings::class)->forAsset($account, $asset);

        $this->assertSame(0, $account->holdings()->count());
    }

    public function test_cash_nets_deposits_against_withdrawals(): void
    {
        $account = $this->account();
        $asset = app(AssetResolver::class)->resolve('CASH-SAR');

        $this->record($account, $asset, TransactionType::Deposit, 50000, 1, '2026-01-01');
        $this->record($account, $asset, TransactionType::Withdrawal, 20000, 1, '2026-02-01');

        app(RebuildAccountHoldings::class)->forAsset($account, $asset);

        $holding = $account->holdings()->firstOrFail();
        $this->assertEqualsWithDelta(30000.0, $holding->quantity, 1e-9);
        $this->assertEqualsWithDelta(1.0, $holding->avg_cost, 1e-9);
    }
}
