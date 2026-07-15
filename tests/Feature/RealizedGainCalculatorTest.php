<?php

namespace Tests\Feature;

use App\Actions\CreateManualAccount;
use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Asset;
use App\Models\User;
use App\Services\Analytics\RealizedGainCalculator;
use App\Services\Markets\AssetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealizedGainCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user): Account
    {
        return app(CreateManualAccount::class)->handle($user, 'Ledger', AccountType::Brokerage, 'SAR');
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

    public function test_a_position_never_sold_has_no_realized_gain(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $asset = app(AssetResolver::class)->resolve('2222.SR');

        $this->record($account, $asset, TransactionType::Buy, 100, 10, '2026-01-01');

        $this->assertEqualsWithDelta(0.0, app(RealizedGainCalculator::class)->forAsset($user, $asset), 1e-9);
        $this->assertEqualsWithDelta(0.0, app(RealizedGainCalculator::class)->forUser($user), 1e-9);
    }

    public function test_a_sell_books_the_gain_against_average_cost(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $asset = app(AssetResolver::class)->resolve('2222.SR');

        $this->record($account, $asset, TransactionType::Buy, 100, 10, '2026-01-01');
        $this->record($account, $asset, TransactionType::Buy, 100, 20, '2026-02-01');
        // Average cost is now 15; selling 50 at 25 books 50 × (25 − 15) = 500.
        $this->record($account, $asset, TransactionType::Sell, 50, 25, '2026-03-01');

        $this->assertEqualsWithDelta(500.0, app(RealizedGainCalculator::class)->forAsset($user, $asset), 1e-9);
        $this->assertEqualsWithDelta(500.0, app(RealizedGainCalculator::class)->forUser($user), 1e-6);
    }

    public function test_a_later_buy_does_not_change_an_earlier_sells_realized_gain(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $asset = app(AssetResolver::class)->resolve('2222.SR');

        $this->record($account, $asset, TransactionType::Buy, 100, 10, '2026-01-01');
        $this->record($account, $asset, TransactionType::Sell, 40, 18, '2026-02-01'); // 40 × (18 − 10) = 320
        $this->record($account, $asset, TransactionType::Buy, 60, 40, '2026-03-01'); // raises avg cost afterwards

        $this->assertEqualsWithDelta(320.0, app(RealizedGainCalculator::class)->forAsset($user, $asset), 1e-9);
    }

    public function test_lots_are_isolated_per_account(): void
    {
        $user = User::factory()->create();
        $asset = app(AssetResolver::class)->resolve('2222.SR');

        $first = $this->account($user);
        $this->record($first, $asset, TransactionType::Buy, 100, 10, '2026-01-01');
        $this->record($first, $asset, TransactionType::Sell, 100, 15, '2026-02-01'); // +500

        $second = $this->account($user);
        $this->record($second, $asset, TransactionType::Buy, 100, 30, '2026-01-01');
        $this->record($second, $asset, TransactionType::Sell, 100, 20, '2026-02-01'); // −1000

        $this->assertEqualsWithDelta(-500.0, app(RealizedGainCalculator::class)->forAsset($user, $asset), 1e-9);
    }
}
