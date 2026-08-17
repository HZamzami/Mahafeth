<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_has_snapshot_false_for_a_fresh_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.has_snapshot', false);
    }

    public function test_dashboard_returns_the_latest_snapshot_summary(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.has_snapshot', true);
        $response->assertJsonPath('data.health_score', $user->latestSnapshot()->health_score);
        $response->assertJsonStructure([
            'data' => [
                'as_of',
                'total_value',
                'health_score',
                'component_scores',
                'top_holdings' => [['symbol', 'name', 'value', 'weight', 'currency']],
                'allocations',
            ],
        ]);

        $weights = collect($response->json('data.top_holdings'))->pluck('weight');
        $this->assertLessThanOrEqual(5, $weights->count());
        $this->assertSame($weights->sortDesc()->values()->all(), $weights->values()->all());
    }

    public function test_dashboard_includes_a_delta_against_the_prior_day_snapshot(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => now()->subDay()->toDateString(),
            'total_value' => 100_000,
            'health_score' => 60,
        ]);

        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => now()->toDateString(),
            'total_value' => 110_000,
            'health_score' => 65,
        ]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.total_value_delta', 10_000);
        $response->assertJsonPath('data.health_score_delta', 5);
    }

    public function test_dashboard_refresh_dispatches_the_analysis_job_without_running_it_synchronously(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/dashboard/refresh');

        $response->assertStatus(202);
        Queue::assertPushed(AnalyzePortfolioJob::class, fn (AnalyzePortfolioJob $job): bool => $job->user->is($user));
        $this->assertSame(0, $user->portfolioSnapshots()->count());
    }

    public function test_dashboard_refresh_is_rate_limited(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/dashboard/refresh');
        }

        $this->postJson('/api/v1/dashboard/refresh')->assertStatus(429);
    }

    private function syncedAndAnalyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user);

        return $user->fresh();
    }
}
