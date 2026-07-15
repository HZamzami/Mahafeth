<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\HealthDeltaExplainer;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class HealthDeltaExplanationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int>  $components
     * @param  array<string, mixed>  $metrics
     */
    private function snapshot(User $user, string $asOf, array $components, array $metrics = []): PortfolioSnapshot
    {
        return PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'as_of' => $asOf,
            'health_score' => (int) round(array_sum($components) / max(1, count($components))),
            'component_scores' => $components,
            'metrics' => $metrics,
        ]);
    }

    public function test_the_biggest_component_mover_comes_first_with_its_driver(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13',
            ['concentration' => 80, 'diversification' => 70],
            ['effective_holdings' => 6.0],
        );
        $current = $this->snapshot($user, '2026-07-14',
            ['concentration' => 50, 'diversification' => 65],
            [
                'effective_holdings' => 5.0,
                'largest_position' => ['symbol' => '2222.SR', 'name' => 'Saudi Aramco', 'weight' => 0.32],
            ],
        );

        $movers = app(HealthDeltaExplainer::class)->explain($current, $previous);

        $this->assertSame('concentration', $movers[0]['component']);
        $this->assertSame(-30, $movers[0]['delta']);
        $this->assertSame(':name is now :weight of the portfolio.', $movers[0]['driver_key']);
        $this->assertSame('Saudi Aramco', $movers[0]['driver_params']['name']);

        $this->assertSame('diversification', $movers[1]['component']);
        $this->assertSame('6.0', $movers[1]['driver_params']['from']);
    }

    public function test_small_or_missing_movements_produce_no_explanation(): void
    {
        $user = User::factory()->create();
        $previous = $this->snapshot($user, '2026-07-13', ['concentration' => 80]);
        $current = $this->snapshot($user, '2026-07-14', ['concentration' => 81]);

        $this->assertSame([], app(HealthDeltaExplainer::class)->explain($current, $previous));
        $this->assertSame([], app(HealthDeltaExplainer::class)->explain($current, null));

        $unscored = PortfolioSnapshot::factory()->create(['user_id' => $user->id, 'as_of' => '2026-07-12', 'component_scores' => null]);
        $this->assertSame([], app(HealthDeltaExplainer::class)->explain($current, $unscored));
    }

    public function test_a_score_change_activity_event_carries_the_top_driver(): void
    {
        $user = User::factory()->create();
        RiskProfile::factory()->create(['user_id' => $user->id]);
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        app(SyncConnection::class)->handle($connection);

        // A perfect prior day guarantees every real component reads as a
        // mover, so the recorded event must carry a driver.
        $this->snapshot($user, now()->subDay()->toDateString(), [
            'diversification' => 100, 'risk_alignment' => 100, 'correlation' => 100,
            'performance' => 100, 'drawdown' => 100, 'concentration' => 100,
        ], ['effective_holdings' => 50.0, 'volatility' => 0.01]);
        $user->portfolioSnapshots()->update(['health_score' => 100]);

        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        $event = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::ScoreChanged)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayHasKey('driver_key', $event->params);
        $this->assertNotSame('', ActivityType::ScoreChanged->label($event->params));
    }

    public function test_the_dashboard_shows_the_what_changed_strip(): void
    {
        $user = User::factory()->create();
        $this->snapshot($user, '2026-07-13', ['concentration' => 80], []);
        $this->snapshot($user, '2026-07-14', ['concentration' => 50], [
            'largest_position' => ['symbol' => '2222.SR', 'name' => 'Saudi Aramco', 'weight' => 0.32],
        ]);

        $this->actingAs($user);

        Volt::test('dashboard.health-score')
            ->assertSee(__('What changed'))
            ->assertSee('Saudi Aramco');
    }

    public function test_the_strip_hides_when_the_score_is_unchanged(): void
    {
        $user = User::factory()->create();
        $this->snapshot($user, '2026-07-13', ['concentration' => 80]);
        $this->snapshot($user, '2026-07-14', ['concentration' => 80]);

        $this->actingAs($user);

        Volt::test('dashboard.health-score')->assertDontSee(__('What changed'));
    }
}
