<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\RiskTolerance;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvestorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/investor-profile')->assertRedirect('/login');
    }

    public function test_advancing_without_an_answer_shows_a_validation_error(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('investor-profile.index')
            ->call('next')
            ->assertHasErrors('answers.age');
    }

    /**
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function answers(array $overrides = []): array
    {
        // All 3s on the risk questions with a 30–45 age bracket (inverted to
        // a 3) → total 21 → Growth band.
        return array_merge([
            'age' => 2,
            'horizon' => 3,
            'goal' => 3,
            'drop_reaction' => 3,
            'experience' => 3,
            'liquidity' => 3,
            'target_return' => 3,
            'contributions' => 1,
            'base_currency' => 1,
            'shariah' => 1,
        ], $overrides);
    }

    public function test_completing_the_questionnaire_persists_a_mapped_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('investor-profile.index')
            ->set('answers', $this->answers())
            ->call('submit')
            ->assertRedirect(route('dashboard'));

        $profile = $user->riskProfile()->first();

        $this->assertNotNull($profile);
        $this->assertSame(RiskTolerance::Growth, $profile->risk_tolerance);
        $this->assertEqualsWithDelta(RiskTolerance::Growth->targetVolatility(), $profile->target_volatility, 1e-6);
        $this->assertEqualsWithDelta(RiskTolerance::Growth->targetReturn(), $profile->target_return, 1e-6);
        $this->assertTrue($profile->constraints['shariah_required']);
        $this->assertFalse($profile->constraints['shariah_preferred']);
        $this->assertSame('SAR', $profile->constraints['base_currency']);
        $this->assertSame('monthly', $profile->constraints['contribution_frequency']);
    }

    public function test_declining_the_shariah_requirement_persists_an_unconstrained_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('investor-profile.index')
            ->set('answers', $this->answers(['shariah' => 3]))
            ->call('submit');

        $profile = $user->riskProfile()->first();

        $this->assertFalse($profile->constraints['shariah_required']);
        $this->assertSame(RiskTolerance::Growth, $profile->risk_tolerance);
    }

    public function test_the_constraint_questions_do_not_shift_the_risk_band(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('investor-profile.index')
            ->set('answers', $this->answers(['contributions' => 4, 'base_currency' => 4, 'shariah' => 3]))
            ->call('submit');

        $profile = $user->riskProfile()->first();

        $this->assertSame(RiskTolerance::Growth, $profile->risk_tolerance);
        $this->assertSame('other', $profile->constraints['base_currency']);
        $this->assertSame('none', $profile->constraints['contribution_frequency']);
    }

    public function test_an_older_investor_lands_in_a_lower_risk_band_than_a_younger_one(): void
    {
        $older = User::factory()->create();
        $this->actingAs($older);

        // Same answers except age: Over 60 scores 1 (total 19, Balanced)
        // where Under 30 scores 4 (total 22, Growth).
        Volt::test('investor-profile.index')
            ->set('answers', $this->answers(['age' => 4]))
            ->call('submit');

        $younger = User::factory()->create();
        $this->actingAs($younger);

        Volt::test('investor-profile.index')
            ->set('answers', $this->answers(['age' => 1]))
            ->call('submit');

        $this->assertSame(RiskTolerance::Balanced, $older->riskProfile()->first()->risk_tolerance);
        $this->assertSame(RiskTolerance::Growth, $younger->riskProfile()->first()->risk_tolerance);
    }

    public function test_submitting_with_a_synced_portfolio_computes_the_health_score(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        app(SyncConnection::class)->handle($connection);

        $this->actingAs($user);

        Volt::test('investor-profile.index')
            ->set('answers', $this->answers(['horizon' => 2, 'goal' => 2, 'drop_reaction' => 2, 'experience' => 2, 'liquidity' => 3, 'target_return' => 2]))
            ->call('submit');

        $snapshot = $user->fresh()->latestSnapshot();

        $this->assertNotNull($snapshot->health_score);
        $this->assertIsInt($snapshot->health_score);

        foreach (['diversification', 'correlation', 'risk_alignment', 'performance', 'drawdown', 'concentration'] as $component) {
            $this->assertArrayHasKey($component, $snapshot->component_scores);
        }
    }

    public function test_the_dashboard_nudges_users_without_a_profile(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(__('Complete your investor profile'));
    }

    public function test_the_dashboard_does_not_nudge_users_with_a_profile(): void
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertDontSee(__('Answer five quick questions'));
    }
}
