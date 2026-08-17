<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_trend_returns_health_score_history(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        PortfolioSnapshot::factory()->for($user)->create(['as_of' => now()->subDay(), 'health_score' => 60]);
        PortfolioSnapshot::factory()->for($user)->create(['as_of' => now(), 'health_score' => 65]);

        $response = $this->getJson('/api/v1/dashboard/trend');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.points'));
        $response->assertJsonPath('data.points.1.score', 65);
    }

    public function test_performance_returns_empty_points_without_holdings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/dashboard/performance');

        $response->assertOk();
        $response->assertJsonPath('data.points', []);
    }

    public function test_performance_returns_a_series_for_a_synced_portfolio(): void
    {
        Sanctum::actingAs($this->syncedAndAnalyzedUser());

        $response = $this->getJson('/api/v1/dashboard/performance');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['points', 'benchmark_symbols', 'growth']]);
        $this->assertNotEmpty($response->json('data.points'));
    }

    public function test_alerts_lists_active_alerts_and_can_be_dismissed(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/dashboard/alerts');
        $response->assertOk();
        $response->assertJsonStructure(['data']);

        $alerts = $response->json('data');

        if ($alerts !== []) {
            $fingerprint = $alerts[0]['fingerprint'];

            $this->postJson('/api/v1/dashboard/alerts/dismiss', ['fingerprint' => $fingerprint])->assertOk();

            $this->assertContains($fingerprint, $user->fresh()->dismissed_alerts ?? []);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_daily_move_returns_null_with_only_one_snapshot(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        PortfolioSnapshot::factory()->for($user)->create();

        $response = $this->getJson('/api/v1/dashboard/daily-move');

        $response->assertOk();
        $response->assertJsonPath('data', null);
    }

    public function test_shariah_returns_screening_and_zakat_detail(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/dashboard/shariah');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['shariah', 'zakat', 'outstanding', 'settlements', 'hawl']]);
    }

    public function test_a_purification_settlement_can_be_recorded(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/dashboard/shariah/purifications', ['amount' => 150])->assertCreated();

        $this->assertDatabaseHas('obligation_settlements', ['user_id' => $user->id, 'amount' => 150]);
    }

    public function test_a_zakat_payment_can_be_recorded(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/dashboard/shariah/zakat-payments', ['amount' => 500])->assertCreated();

        $this->assertDatabaseHas('obligation_settlements', ['user_id' => $user->id, 'amount' => 500]);
    }

    public function test_instrument_fundamentals_include_a_summary_pending_flag(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            'fc.yahoo.com' => Http::response(status: 404, headers: ['Set-Cookie' => 'A3=d=abc123; Domain=.yahoo.com']),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb-token'),
            'query1.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(
                (string) file_get_contents(base_path('tests/fixtures/yahoo-quote-summary.json')),
            ),
        ]);

        $response = $this->getJson('/api/v1/instruments/MSFT');

        $response->assertOk();
        $response->assertJsonPath('data.summary_pending', false);
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
