<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\Institution;
use App\Models\User;
use Database\Seeders\InstitutionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The full competition demo, as one test: register → investor profile →
 * connect via Open Banking → analysis → health score → AI action plan.
 */
class DemoJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_complete_investor_journey(): void
    {
        (new InstitutionSeeder)->run();

        // 1. Register and verify.
        Volt::test('auth.register')
            ->set('name', 'Journey Investor')
            ->set('email', 'journey@mahafeth.test')
            ->set('password', 'secret-password')
            ->set('password_confirmation', 'secret-password')
            ->call('register');

        $user = User::where('email', 'journey@mahafeth.test')->firstOrFail();
        $user->markEmailAsVerified();
        $this->actingAs($user);

        // 2. The dashboard nudges toward the investor profile.
        $this->get('/dashboard')->assertOk()->assertSee(__('Complete your investor profile'));

        // 3. Complete the IPS questionnaire.
        Volt::test('investor-profile.index')
            ->set('answers', ['age' => 2, 'horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'experience' => 3, 'liquidity' => 3, 'target_return' => 2, 'contributions' => 1, 'base_currency' => 1, 'shariah' => 1])
            ->call('submit')
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->riskProfile);

        // 4. Connect every API institution through the consent journey.
        foreach (Institution::where('provider', '!=', 'import')->get() as $institution) {
            Volt::test('connections.consent', ['institution' => $institution])->call('approve');
        }

        $user = $user->fresh();
        $this->assertSame(4, $user->connections()->count());
        $this->assertSame(4, $user->consents()->count());

        // 5. The analysis produced a scored snapshot.
        $snapshot = $user->latestSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->health_score);
        $this->assertGreaterThan(0, $snapshot->total_value);

        // 6. The dashboard is fully dynamic.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('AAPL')
            ->assertDontSee(__('Complete your investor profile to unlock scoring'));

        // 7. The analytics page renders the frontier and correlation matrix.
        $this->get('/analytics')
            ->assertOk()
            ->assertSee(__('Efficient Frontier'))
            ->assertSee(__('Correlation Matrix'));

        // 8. Generate the AI insight (fake generator in tests; the sync
        // queue runs the job inline) and open the advisor conversation.
        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertSee(__('Top recommendation'));

        $this->assertSame(1, AiInsight::count());

        $this->get('/advisor')
            ->assertOk()
            ->assertSee(__('Executive Summary'))
            ->assertSee(__('Discuss this'));

        Volt::test('advisor.index')
            ->set('message', 'What is my biggest risk?')
            ->call('send')
            ->assertSee('What is my biggest risk?');
    }

    public function test_demo_reset_builds_the_golden_demo_in_one_command(): void
    {
        $this->artisan('demo:reset', ['--fresh' => true])->assertSuccessful();

        $demo = User::where('email', 'demo@mahafeth.test')->firstOrFail();

        // Alinma Bank, Derayah, and Rain via API sync plus Alinma Capital via import.
        $this->assertSame(4, $demo->connections()->count());
        $this->assertSame('import', $demo->connections()->whereRelation('institution', 'slug', 'alinma-capital')->firstOrFail()->source);
        $this->assertNotNull($demo->riskProfile);
        $this->assertNotNull($demo->latestSnapshot()?->health_score);
        $this->assertGreaterThan(20, $demo->portfolioSnapshots()->whereNotNull('health_score')->count());

        // The contrasting conservative persona is scored too.
        $sara = User::where('email', 'sara@mahafeth.test')->firstOrFail();

        $this->assertSame(1, $sara->connections()->count());
        $this->assertNotNull($sara->latestSnapshot()?->health_score);
        $this->assertGreaterThan($demo->latestSnapshot()->health_score, $sara->latestSnapshot()->health_score);
    }
}
