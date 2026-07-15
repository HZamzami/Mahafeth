<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\TransactionType;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Analytics\NetFlowCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ContributionGrowthTest extends TestCase
{
    use RefreshDatabase;

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::firstOrCreate(
            ['slug' => 'derayah'],
            Institution::factory()->raw(['slug' => 'derayah']),
        );
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user;
    }

    public function test_deposits_and_withdrawals_sum_into_net_flows(): void
    {
        $user = $this->syncedUser();
        $account = $user->connections()->first()->accounts()->first();
        $before = app(NetFlowCalculator::class)->flows($user, now()->subYears(10));

        Transaction::create([
            'account_id' => $account->id,
            'type' => TransactionType::Deposit,
            'amount' => 5000.0,
            'executed_at' => now()->subMonth(),
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'type' => TransactionType::Withdrawal,
            'amount' => -1200.0,
            'executed_at' => now()->subWeek(),
        ]);

        $flows = app(NetFlowCalculator::class)->flows($user, now()->subYears(10));

        $this->assertEqualsWithDelta($before['deposits'] + 5000.0, $flows['deposits'], 0.01);
        $this->assertEqualsWithDelta($before['withdrawals'] + 1200.0, $flows['withdrawals'], 0.01);
        $this->assertEqualsWithDelta($flows['deposits'] - $flows['withdrawals'], $flows['net'], 0.01);
    }

    public function test_flows_respect_the_window_and_ownership(): void
    {
        $user = $this->syncedUser();
        $stranger = $this->syncedUser();
        $account = $user->connections()->first()->accounts()->first();

        $strangerBefore = app(NetFlowCalculator::class)->flows($stranger, now()->subYears(10));
        $userNarrowBefore = app(NetFlowCalculator::class)->flows($user, now()->subDay());

        Transaction::create([
            'account_id' => $account->id,
            'type' => TransactionType::Deposit,
            'amount' => 999.0,
            'executed_at' => now()->subMonth(),
        ]);

        // Outside the stranger's ownership and outside the narrow window.
        $this->assertSame($strangerBefore, app(NetFlowCalculator::class)->flows($stranger, now()->subYears(10)));
        $this->assertSame($userNarrowBefore, app(NetFlowCalculator::class)->flows($user, now()->subDay()));
    }

    public function test_buys_and_sells_do_not_count_as_contributions(): void
    {
        $user = $this->syncedUser();
        $account = $user->connections()->first()->accounts()->first();

        $before = app(NetFlowCalculator::class)->flows($user, now()->subYears(10));

        Transaction::create([
            'account_id' => $account->id,
            'type' => TransactionType::Buy,
            'amount' => -8000.0,
            'quantity' => 10,
            'price' => 800.0,
            'executed_at' => now()->subDay(),
        ]);

        $this->assertSame($before, app(NetFlowCalculator::class)->flows($user, now()->subYears(10)));
    }

    public function test_the_chart_shows_the_deposits_versus_growth_line(): void
    {
        $user = $this->syncedUser();

        $this->actingAs($user);

        Volt::test('dashboard.performance-chart')
            ->assertSee(__('Total Return'))
            ->assertSee('you added');
    }
}
