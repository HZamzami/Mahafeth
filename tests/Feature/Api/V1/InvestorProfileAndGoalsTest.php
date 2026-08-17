<?php

namespace Tests\Feature\Api\V1;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvestorProfileAndGoalsTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWERS = [
        'age' => 2, 'horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'experience' => 2,
        'liquidity' => 4, 'target_return' => 3, 'contributions' => 1, 'base_currency' => 1, 'shariah' => 3,
    ];

    public function test_show_returns_null_without_a_profile(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/investor-profile');

        $response->assertOk();
        $response->assertJsonPath('data', null);
    }

    public function test_update_creates_a_risk_profile(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->putJson('/api/v1/investor-profile', ['answers' => self::ANSWERS]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['risk_tolerance', 'time_horizon', 'target_return', 'target_volatility', 'constraints']]);
        $response->assertJsonPath('data.constraints.base_currency', 'SAR');
    }

    public function test_update_requires_every_answer(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $answers = self::ANSWERS;
        unset($answers['shariah']);

        $this->putJson('/api/v1/investor-profile', ['answers' => $answers])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.shariah');
    }

    public function test_goals_can_be_created_listed_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/goals', [
            'name' => 'Retirement',
            'target_amount' => 500000,
            'target_date' => now()->addYears(10)->toDateString(),
        ]);
        $create->assertCreated();
        $goalId = $create->json('data.id');

        $this->getJson('/api/v1/goals')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/goals/{$goalId}", [
            'name' => 'Early Retirement',
            'target_amount' => 600000,
            'target_date' => now()->addYears(8)->toDateString(),
        ])->assertOk()->assertJsonPath('data.name', 'Early Retirement');

        $this->deleteJson("/api/v1/goals/{$goalId}")->assertOk();
        $this->assertDatabaseMissing('goals', ['id' => $goalId]);
    }

    public function test_a_goal_cannot_be_modified_by_another_user(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/goals/{$goal->id}", [
            'name' => 'Hijack',
            'target_amount' => 1,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertNotFound();
    }
}
