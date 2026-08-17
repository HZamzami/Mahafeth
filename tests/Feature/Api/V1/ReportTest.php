<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Goal;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_returns_has_snapshot_false_for_a_fresh_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/report');

        $response->assertOk();
        $response->assertJsonPath('data.has_snapshot', false);
    }

    public function test_report_assembles_snapshot_holdings_and_goal_forecasts(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Goal::factory()->for($user)->create([
            'name' => 'Retirement',
            'target_amount' => 500_000,
            'target_date' => now()->addYears(5),
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/report');

        $response->assertOk();
        $response->assertJsonPath('data.has_snapshot', true);
        $response->assertJsonStructure([
            'data' => ['total_value', 'health_score', 'component_scores', 'goal_forecasts', 'rebalance_orders', 'holdings', 'realized_pl', 'insight'],
        ]);
        $this->assertCount(1, $response->json('data.goal_forecasts'));
        $response->assertJsonPath('data.goal_forecasts.0.name', 'Retirement');
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
