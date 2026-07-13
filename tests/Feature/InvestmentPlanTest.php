<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\InvestmentPlan;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\EfficientFrontierService;
use App\Services\Analytics\InvestmentPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvestmentPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user with a balanced profile plus the demo asset catalog (the
     * derayah sync seeds assets with a year of price history).
     */
    private function profiledUser(?array $constraints = null): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create([
            'user_id' => $user->id,
            'constraints' => $constraints,
        ]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user->fresh();
    }

    public function test_the_frontier_target_pick_respects_the_volatility_budget(): void
    {
        $expectedReturns = ['SAFE' => 0.04, 'WILD' => 0.20];
        $covariance = [
            'SAFE' => ['SAFE' => 0.0025, 'WILD' => 0.0],
            'WILD' => ['SAFE' => 0.0, 'WILD' => 0.09],
        ];

        $result = app(EfficientFrontierService::class)->analyze(
            $expectedReturns,
            $covariance,
            ['SAFE' => 0.5, 'WILD' => 0.5],
            samples: 2000,
            targetVolatility: 0.10,
        );

        $this->assertNotNull($result['target']);
        $this->assertLessThanOrEqual(0.10, $result['target']['risk']);
        $this->assertEqualsWithDelta(1.0, array_sum($result['target']['weights']), 0.001);

        // Without a target, no pick is made and existing output is intact.
        $without = app(EfficientFrontierService::class)->analyze($expectedReturns, $covariance, ['SAFE' => 0.5, 'WILD' => 0.5], samples: 500);
        $this->assertNull($without['target']);
        $this->assertArrayHasKey('tangency', $without);
    }

    public function test_the_builder_produces_a_practical_plan_matched_to_the_profile(): void
    {
        $user = $this->profiledUser();

        $plan = app(InvestmentPlanBuilder::class)->build($user, 100000, 2000);

        $this->assertNotNull($plan);
        $this->assertEqualsWithDelta(1.0, array_sum($plan['weights']), 0.01);
        $this->assertLessThanOrEqual(8, count($plan['weights']));

        foreach ($plan['weights'] as $weight) {
            $this->assertGreaterThanOrEqual(0.02, round($weight, 4));
        }

        // Orders spend at most the starting amount, at real prices.
        $this->assertNotEmpty($plan['orders']);
        $this->assertLessThanOrEqual(100000 * 1.01, array_sum(array_column($plan['orders'], 'value')));

        $this->assertGreaterThan(0, $plan['metrics']['volatility']);
        $this->assertSame(0.15, $plan['metrics']['target_volatility']);
        $this->assertNotEmpty($plan['forecast']['bands']['p50']);
    }

    public function test_a_shariah_bound_plan_only_holds_compliant_instruments(): void
    {
        $user = $this->profiledUser(['shariah_required' => true]);

        $plan = app(InvestmentPlanBuilder::class)->build($user, 100000);

        $this->assertNotNull($plan);
        $this->assertTrue($plan['metrics']['shariah_applied']);

        $statuses = Asset::whereIn('symbol', array_keys($plan['weights']))->pluck('shariah_status');
        $this->assertTrue($statuses->every(fn (ShariahStatus $status): bool => $status === ShariahStatus::Compliant));
    }

    public function test_the_builder_returns_null_without_a_risk_profile(): void
    {
        $this->assertNull(app(InvestmentPlanBuilder::class)->build(User::factory()->create(), 100000));
    }

    public function test_the_page_shows_the_profile_cta_without_a_risk_profile(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/plan')
            ->assertOk()
            ->assertSee(__('Build your investor profile'))
            ->assertDontSee(__('Build my plan'));
    }

    public function test_generating_persists_one_plan_per_user_and_renders_the_sections(): void
    {
        $user = $this->profiledUser();
        $this->actingAs($user);

        $component = Volt::test('investment-plan.index')
            ->set('amount', 50000)
            ->set('monthlyContribution', 1000)
            ->call('generate')
            ->assertDispatched('toast');

        $this->assertSame(1, InvestmentPlan::whereBelongsTo($user)->count());
        $plan = InvestmentPlan::whereBelongsTo($user)->first();
        $this->assertSame(50000.0, $plan->amount);

        $component
            ->assertSee(__('Proposed Allocation'))
            ->assertSee(__('Your Starter Buy List'))
            ->assertSee(__('How It Could Grow'))
            ->assertSee(__('Ask Mahafeth AI about this plan'));

        // Regenerating replaces the plan instead of stacking a second row.
        Volt::test('investment-plan.index')
            ->set('amount', 80000)
            ->call('generate');

        $this->assertSame(1, InvestmentPlan::whereBelongsTo($user)->count());
        $this->assertSame(80000.0, InvestmentPlan::whereBelongsTo($user)->first()->amount);
    }

    public function test_the_arabic_locale_renders_the_plan_page(): void
    {
        $user = $this->profiledUser();
        $this->actingAs($user)->withSession(['locale' => 'ar']);

        $this->get('/plan')
            ->assertOk()
            ->assertSee('خطة الاستثمار');
    }
}
