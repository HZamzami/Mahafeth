<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Analytics\DailyMoveAttributor;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DailyMoveAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user;
    }

    /**
     * @param  array<string, array<string, mixed>>  $holdings
     */
    private function snapshot(User $user, string $asOf, float $totalValue, array $holdings): PortfolioSnapshot
    {
        return PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'as_of' => $asOf,
            'total_value' => $totalValue,
            'metrics' => ['holdings' => $holdings],
        ]);
    }

    private function holding(float $quantity, float $nativeClose, float $fxRate, string $currency = 'SAR'): array
    {
        return [
            'quantity' => $quantity,
            'native_close' => $nativeClose,
            'fx_rate' => $fxRate,
            'value' => round($quantity * $nativeClose * $fxRate, 4),
            'weight' => 1.0,
            'currency' => $currency,
            'name' => 'Test Asset',
            'price_date' => '2026-07-14',
        ];
    }

    public function test_the_analyzer_persists_per_holding_valuation_state(): void
    {
        $user = $this->syncedUser();

        $holdings = app(PortfolioAnalyzer::class)->analyze($user)->metrics['holdings'];

        $this->assertNotEmpty($holdings);

        foreach ($holdings as $state) {
            foreach (['quantity', 'native_close', 'fx_rate', 'value', 'weight', 'currency', 'name', 'price_date'] as $key) {
                $this->assertArrayHasKey($key, $state);
            }

            $this->assertEqualsWithDelta(
                $state['quantity'] * $state['native_close'] * $state['fx_rate'],
                $state['value'],
                0.01,
            );
        }
    }

    public function test_a_pure_price_move_lands_entirely_in_the_price_leg(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13', 1000.0, ['2222.SR' => $this->holding(10, 100.0, 1.0)]);
        $current = $this->snapshot($user, '2026-07-14', 1100.0, ['2222.SR' => $this->holding(10, 110.0, 1.0)]);

        $move = app(DailyMoveAttributor::class)->attribute($current, $previous);

        $this->assertEqualsWithDelta(0.10, $move['total_change_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $move['contributions'][0]['pct'], 1e-9);
        $this->assertSame([], $move['fx']);
        $this->assertEqualsWithDelta(0.0, $move['flows_pct'], 1e-9);
    }

    public function test_an_fx_rate_change_on_a_foreign_asset_lands_in_the_fx_leg(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13', 375.0, ['AAPL' => $this->holding(1, 100.0, 3.75, 'USD')]);
        $current = $this->snapshot($user, '2026-07-14', 370.0, ['AAPL' => $this->holding(1, 100.0, 3.70, 'USD')]);

        $move = app(DailyMoveAttributor::class)->attribute($current, $previous);

        $this->assertEqualsWithDelta(-0.05 / 3.75 * 100 / 100, $move['total_change_pct'], 1e-9);
        $this->assertSame('USD', $move['fx'][0]['currency']);
        $this->assertEqualsWithDelta($move['total_change_pct'], $move['fx'][0]['pct'], 1e-9);
        $this->assertSame([], $move['contributions']);
    }

    public function test_quantity_changes_land_in_the_flows_bucket_not_the_price_leg(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13', 1000.0, ['2222.SR' => $this->holding(10, 100.0, 1.0)]);
        $current = $this->snapshot($user, '2026-07-14', 1500.0, ['2222.SR' => $this->holding(15, 100.0, 1.0)]);

        $move = app(DailyMoveAttributor::class)->attribute($current, $previous);

        $this->assertEqualsWithDelta(0.50, $move['flows_pct'], 1e-9);
        $this->assertSame([], $move['contributions']);
    }

    public function test_the_three_legs_sum_to_the_total_change(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13', 1375.0, [
            '2222.SR' => $this->holding(10, 100.0, 1.0),
            'AAPL' => $this->holding(1, 100.0, 3.75, 'USD'),
        ]);
        $current = $this->snapshot($user, '2026-07-14', 1562.0, [
            '2222.SR' => $this->holding(11, 105.0, 1.0),
            'AAPL' => $this->holding(1, 110.0, 3.70, 'USD'),
        ]);

        $move = app(DailyMoveAttributor::class)->attribute($current, $previous);

        $legs = array_sum(array_column($move['contributions'], 'pct'))
            + array_sum(array_column($move['fx'], 'pct'))
            + $move['flows_pct'];

        $this->assertEqualsWithDelta($move['total_change_pct'], $legs, 1e-9);
    }

    public function test_attribution_is_null_when_a_snapshot_predates_holding_tracking(): void
    {
        $user = User::factory()->create();
        $previous = PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'as_of' => '2026-07-13',
            'metrics' => ['volatility' => 0.2],
        ]);
        $current = $this->snapshot($user, '2026-07-14', 1000.0, ['2222.SR' => $this->holding(10, 100.0, 1.0)]);

        $this->assertNull(app(DailyMoveAttributor::class)->attribute($current, $previous));
        $this->assertNull(app(DailyMoveAttributor::class)->attribute($current, null));
    }

    public function test_the_dashboard_card_renders_the_move_summary(): void
    {
        $user = User::factory()->create();
        $this->snapshot($user, '2026-07-13', 1000.0, ['2222.SR' => $this->holding(10, 100.0, 1.0)]);
        $this->snapshot($user, '2026-07-14', 1100.0, ['2222.SR' => $this->holding(10, 110.0, 1.0)]);

        $this->actingAs($user);

        Volt::test('dashboard.daily-move')
            ->assertSee(__('Daily Move'))
            ->assertSee('Test Asset');
    }

    public function test_the_card_hides_with_a_single_snapshot(): void
    {
        $user = User::factory()->create();
        $this->snapshot($user, '2026-07-14', 1000.0, ['2222.SR' => $this->holding(10, 100.0, 1.0)]);

        $this->actingAs($user);

        Volt::test('dashboard.daily-move')->assertDontSee(__('Daily Move'));
    }
}
