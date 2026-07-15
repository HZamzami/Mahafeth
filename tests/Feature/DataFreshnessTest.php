<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\FxRate;
use App\Models\Institution;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\DataFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DataFreshnessTest extends TestCase
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

    public function test_fresh_data_is_not_flagged_stale(): void
    {
        $user = $this->syncedUser();
        FxRate::factory()->create(['currency' => 'USD', 'fetched_at' => now()]);

        $freshness = app(DataFreshness::class)->forUser($user);

        $this->assertNotNull($freshness['prices_as_of']);
        $this->assertFalse($freshness['stale_prices']);
        $this->assertFalse($freshness['stale_fx']);
    }

    public function test_old_prices_and_fx_earn_the_stale_flag(): void
    {
        $user = $this->syncedUser();

        PriceHistory::where('date', '>', now()->subDays(10)->toDateString())->delete();
        FxRate::query()->delete();
        FxRate::factory()->create(['currency' => 'USD', 'fetched_at' => now()->subDays(5)]);

        $freshness = app(DataFreshness::class)->forUser($user);

        $this->assertTrue($freshness['stale_prices']);
        $this->assertTrue($freshness['stale_fx']);
    }

    public function test_users_without_holdings_get_no_freshness_data(): void
    {
        $this->assertNull(app(DataFreshness::class)->forUser(User::factory()->create()));
    }

    public function test_the_open_banking_panel_shows_freshness_and_stale_badge(): void
    {
        $user = $this->syncedUser();
        PriceHistory::where('date', '>', now()->subDays(10)->toDateString())->delete();
        $latest = PriceHistory::max('date');

        $this->actingAs($user);

        Volt::test('dashboard.open-banking-panel')
            ->assertSee(__('Prices as of :date', ['date' => Carbon::parse($latest)->translatedFormat('j M')]))
            ->assertSee(__('Stale'));
    }

    public function test_the_panel_omits_freshness_for_users_without_holdings(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.open-banking-panel')
            ->assertDontSee(__('Stale'));
    }
}
