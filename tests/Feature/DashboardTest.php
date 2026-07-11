<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_the_hero_greets_the_user_and_shows_the_total_value(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        $this->actingAs($user);

        $snapshot = $user->latestSnapshot();

        Volt::test('dashboard.portfolio-hero')
            ->assertSee(Str::before($user->name, ' '))
            ->assertSee(__('Total Portfolio'))
            ->assertSee(Number::format($snapshot->total_value, 0));
    }

    public function test_the_hero_shows_the_connect_cta_without_a_snapshot(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.portfolio-hero')
            ->assertSee(__('Your unified portfolio starts here'))
            ->assertSee(__('Connect your accounts'))
            ->assertDontSee(__('Total Portfolio'));
    }

    public function test_onboarding_walks_a_fresh_user_toward_connecting(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(__('Welcome to Mahafeth'))
            ->assertSee(__('Connect your accounts'))
            ->assertSee(__('Run your first analysis'));
    }

    public function test_onboarding_runs_the_first_analysis_and_hides_itself(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        $user->portfolioSnapshots()->delete();
        RiskProfile::factory()->for($user)->create();
        $this->actingAs($user);

        Volt::test('dashboard.onboarding')
            ->assertSee(__('Analyze now'))
            ->call('analyze')
            ->assertDontSee(__('Welcome to Mahafeth'));

        $this->assertSame(1, $user->portfolioSnapshots()->count());
    }

    public function test_onboarding_is_hidden_for_analyzed_users(): void
    {
        $this->actingAs($this->syncedAndAnalyzedUser());

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee(__('Welcome to Mahafeth'));
    }

    public function test_the_profile_menu_offers_a_theme_toggle(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(__('Dark Mode'))
            ->assertSee(__('Light Mode'));
    }

    public function test_the_dashboard_shows_the_analyzed_portfolio(): void
    {
        $user = $this->syncedAndAnalyzedUser();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('AAPL')
            ->assertSee('Information Technology');
    }

    public function test_the_dashboard_shows_the_shariah_screening_card(): void
    {
        $user = $this->syncedAndAnalyzedUser();

        // Derayah's fixture includes JPM, the non-compliant contrast position.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(__('Shariah Compliance'))
            ->assertSee(__('Flagged Positions'))
            ->assertSee(__('Stock Purification'))
            ->assertSee('https://ehsan.sa/stockspurification')
            ->assertSee(__('Zakat Due'))
            ->assertSee("\u{20C1}") // the official Saudi Riyal sign
            ->assertSee('JPM');
    }

    public function test_the_allocation_donut_shows_asset_class_weights(): void
    {
        $this->actingAs($this->syncedAndAnalyzedUser());

        Volt::test('dashboard.asset-allocation')
            ->assertSee(__('Equities'))
            ->assertSee('100.0%')
            ->assertSeeHtml('data-dasharray');
    }

    public function test_the_health_card_shows_risk_metrics_and_can_refresh(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $user->portfolioSnapshots()->delete();

        Volt::test('dashboard.health-score')
            ->call('refresh')
            ->assertSee(__('Diversification'))
            ->assertSeeHtml('gauge-fill')
            ->assertSeeHtml('data-width');

        $this->assertSame(1, $user->portfolioSnapshots()->count());
    }

    public function test_the_health_trend_collapses_without_enough_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        PortfolioSnapshot::factory()->for($user)->create();

        // One snapshot cannot plot a trend; the card must not reserve
        // layout space (an empty flex child would double the column gap).
        Volt::test('dashboard.health-trend')
            ->assertDontSee(__('Health Trend'))
            ->assertSeeHtml('class="hidden"');
    }

    public function test_the_health_trend_plots_with_enough_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        PortfolioSnapshot::factory()->for($user)->count(2)->create();

        Volt::test('dashboard.health-trend')
            ->assertSee(__('Health Trend'))
            ->assertDontSeeHtml('class="hidden"')
            ->assertSeeHtml('name="cursor"');
    }

    private function syncedAndAnalyzedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        return $user;
    }

    public function test_the_performance_chart_plots_the_synced_portfolio(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        $this->actingAs($user);

        Volt::test('dashboard.performance-chart')
            ->assertViewHas('points', fn (array $points): bool => count($points) > 10);
    }
}
