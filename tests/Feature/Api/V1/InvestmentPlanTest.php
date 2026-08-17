<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestmentPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_reports_no_risk_profile_and_no_plan_for_a_fresh_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/investment-plan');

        $response->assertOk();
        $response->assertJsonPath('data.has_risk_profile', false);
        $response->assertJsonPath('data.plan', null);
    }

    public function test_generating_a_plan_requires_a_risk_profile(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/investment-plan', [
            'amount' => 100000,
            'monthly_contribution' => 0,
        ])->assertStatus(422);
    }

    public function test_a_plan_can_be_generated_and_then_retrieved(): void
    {
        $user = $this->syncedAndAnalyzedUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/investment-plan', [
            'amount' => 100000,
            'monthly_contribution' => 500,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['weights', 'orders', 'metrics', 'forecast']]);

        $show = $this->getJson('/api/v1/investment-plan');
        $show->assertOk();
        $show->assertJsonPath('data.has_risk_profile', true);
        $show->assertJsonPath('data.plan.amount', 100000);
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
