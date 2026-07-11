<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Goal;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GoalsPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithProfile(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_the_goals_section_appears_once_the_profile_exists(): void
    {
        $this->actingAs($this->userWithProfile())
            ->get('/investor-profile')
            ->assertOk()
            ->assertSee(__('Financial Goals'));
    }

    public function test_users_without_a_profile_do_not_see_the_goals_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/investor-profile')
            ->assertOk()
            ->assertDontSee(__('Financial Goals'));
    }

    public function test_a_goal_can_be_created(): void
    {
        $user = $this->userWithProfile();
        $this->actingAs($user);

        Volt::test('investor-profile.goals')
            ->set('name', 'Retirement')
            ->set('targetAmount', '2000000')
            ->set('targetDate', now()->addYears(15)->toDateString())
            ->set('monthlyContribution', '3000')
            ->call('save')
            ->assertHasNoErrors();

        $goal = $user->goals()->firstOrFail();

        $this->assertSame('Retirement', $goal->name);
        $this->assertEqualsWithDelta(2_000_000.0, $goal->target_amount, 1e-9);
        $this->assertEqualsWithDelta(3_000.0, $goal->monthly_contribution, 1e-9);
    }

    public function test_a_goal_can_be_updated_and_deleted(): void
    {
        $user = $this->userWithProfile();
        $goal = Goal::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

        $this->actingAs($user);

        Volt::test('investor-profile.goals')
            ->call('edit', $goal->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $goal->fresh()->name);

        Volt::test('investor-profile.goals')->call('delete', $goal->id);

        $this->assertSame(0, $user->goals()->count());
    }

    public function test_validation_rejects_past_dates_and_missing_amounts(): void
    {
        $this->actingAs($this->userWithProfile());

        Volt::test('investor-profile.goals')
            ->set('name', 'Bad Goal')
            ->set('targetAmount', '')
            ->set('targetDate', now()->subYear()->toDateString())
            ->call('save')
            ->assertHasErrors(['targetAmount', 'targetDate']);
    }

    public function test_the_dashboard_shows_the_goal_forecast_for_an_analyzed_portfolio(): void
    {
        $user = $this->userWithProfile();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        Goal::factory()->create([
            'user_id' => $user->id,
            'name' => 'Retirement',
            'target_date' => now()->addYears(10),
        ]);

        $this->actingAs($user);

        // The card is lazy-loaded on the dashboard, so its content is
        // asserted at the component level.
        Volt::test('dashboard.goal-progress')
            ->assertSee(__('Goal Forecast'))
            ->assertSee('Retirement');
    }

    public function test_the_dashboard_nudges_users_without_goals(): void
    {
        $this->actingAs($this->userWithProfile());

        Volt::test('dashboard.goal-progress')
            ->assertSee(__('Add a financial goal and Mahafeth will forecast your odds of reaching it.'));
    }

    public function test_users_cannot_touch_another_users_goal(): void
    {
        $foreign = Goal::factory()->create();

        $this->actingAs($this->userWithProfile());

        try {
            Volt::test('investor-profile.goals')->call('delete', $foreign->id);
            $this->fail('Expected a ModelNotFoundException for a foreign goal.');
        } catch (ModelNotFoundException) {
            // Goal lookups are scoped to the authenticated user.
        }

        $this->assertNotNull($foreign->fresh());
    }
}
