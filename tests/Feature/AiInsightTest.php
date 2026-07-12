<?php

namespace Tests\Feature;

use App\Actions\GenerateInsights;
use App\Actions\SyncConnection;
use App\Contracts\InsightGenerator;
use App\Jobs\GenerateInsightsJob;
use App\Models\AiInsight;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Insights\FakeInsightGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AiInsightTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user;
    }

    public function test_the_fake_generator_is_bound_when_no_api_key_is_configured(): void
    {
        config(['mahafeth.ai.api_key' => null, 'mahafeth.ai.fake' => false]);

        $this->assertInstanceOf(FakeInsightGenerator::class, app(InsightGenerator::class));
    }

    public function test_generating_insights_persists_a_row_for_the_snapshot_and_locale(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        // The sync test queue runs the job inline, so the teaser renders
        // the finished insight in the same call.
        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertSee(__('Executive Summary'))
            ->assertSee(__('Top recommendation'))
            ->assertSee(__('Ask Mahafeth AI'));

        $insight = AiInsight::first();

        $this->assertNotNull($insight);
        $this->assertSame('en', $insight->locale);
        $this->assertSame($user->latestSnapshot()->id, $insight->portfolio_snapshot_id);
        $this->assertStringContainsString('Apple', $insight->summary);
        $this->assertNotEmpty($insight->recommendations);

        // Every action item cites the metrics that justify it.
        foreach ($insight->recommendations as $recommendation) {
            $this->assertNotEmpty($recommendation['evidence']);
            $this->assertArrayHasKey('metric', $recommendation['evidence'][0]);
            $this->assertArrayHasKey('value', $recommendation['evidence'][0]);
        }
    }

    public function test_regenerating_updates_the_existing_row_instead_of_duplicating(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        $component = Volt::test('dashboard.ai-summary');
        $component->call('generate');
        $component->call('generate');

        $this->assertSame(1, AiInsight::count());
    }

    public function test_insights_are_generated_in_the_arabic_locale(): void
    {
        $user = $this->analyzedUser();

        $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/dashboard');

        app()->setLocale('ar');
        Volt::test('dashboard.ai-summary')->call('generate');

        $insight = AiInsight::first();

        $this->assertSame('ar', $insight->locale);
        $this->assertStringContainsString('درجة صحة محفظتك', $insight->summary);
    }

    public function test_the_fake_generator_flags_non_compliant_holdings_for_shariah_investors(): void
    {
        $user = $this->analyzedUser();
        $user->riskProfile->update(['constraints' => ['shariah_required' => true]]);

        $insight = (new FakeInsightGenerator)->generate(
            $user->latestSnapshot(),
            $user->riskProfile->fresh(),
            'en',
        );

        // Derayah's fixture holds JPM, the non-compliant contrast position.
        $this->assertSame(__('Replace non-compliant holdings'), $insight['recommendations'][0]['title']);
        $this->assertStringContainsString('JPMorgan', $insight['recommendations'][0]['body']);
        $this->assertSame('high', $insight['recommendations'][0]['priority']);
    }

    public function test_generate_is_a_no_op_for_users_without_a_snapshot(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('dashboard.ai-summary')->call('generate');
        Volt::test('advisor.index')->call('generate');

        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has(GenerateInsightsJob::cacheKey($user, 'en')));
    }

    public function test_generate_dispatches_a_unique_job_and_flags_the_generation_as_in_flight(): void
    {
        Queue::fake();

        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('dashboard.ai-summary')->call('generate');

        Queue::assertPushed(GenerateInsightsJob::class, 1);
        $this->assertTrue(Cache::has(GenerateInsightsJob::cacheKey($user, 'en')));
    }

    public function test_the_generating_state_survives_a_reload_and_the_job_clears_it(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        // A fresh component render mid-generation (a reload) shows the
        // persistent analyzing state instead of the Generate button.
        Cache::put(GenerateInsightsJob::cacheKey($user, 'en'), true, now()->addMinutes(5));

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Analyzing your portfolio…'))
            ->assertDontSee(__('Generate Insights'));

        (new GenerateInsightsJob($user, 'en'))->handle(app(GenerateInsights::class));

        $this->assertFalse(Cache::has(GenerateInsightsJob::cacheKey($user, 'en')));

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Executive Summary'))
            ->assertDontSee(__('Analyzing your portfolio…'));
    }

    public function test_a_failed_job_clears_the_in_flight_flag_and_flags_the_failure(): void
    {
        $user = User::factory()->create();

        Cache::put(GenerateInsightsJob::cacheKey($user, 'en'), true, now()->addMinutes(5));

        (new GenerateInsightsJob($user, 'en'))->failed();

        $this->assertFalse(Cache::has(GenerateInsightsJob::cacheKey($user, 'en')));
        $this->assertTrue(Cache::has(GenerateInsightsJob::failedCacheKey($user, 'en')));
    }

    public function test_a_failed_generation_shows_a_retry_message_that_regenerating_clears(): void
    {
        Queue::fake();

        $user = $this->analyzedUser();
        $this->actingAs($user);

        Cache::put(GenerateInsightsJob::failedCacheKey($user, 'en'), true, now()->addMinutes(10));

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Insight generation failed — please try again.'));

        Volt::test('advisor.index')
            ->assertSee(__('Insight generation failed — please try again.'));

        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertDontSee(__('Insight generation failed — please try again.'));

        $this->assertFalse(Cache::has(GenerateInsightsJob::failedCacheKey($user, 'en')));
    }

    public function test_a_same_day_reanalysis_marks_the_insight_as_stale(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertDontSee(__('Your analysis has changed since this was generated.'));

        // A profile edit or a new sync re-analyzes the same day, updating
        // the snapshot row in place after the insight was written.
        AiInsight::query()->update(['updated_at' => now()->subMinute()]);
        $user->latestSnapshot()->touch();

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Your analysis has changed since this was generated.'));

        Volt::test('advisor.index')
            ->assertSee(__('Your analysis has changed since this was generated.'));

        // Regenerating refreshes the insight and clears the nudge.
        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertDontSee(__('Your analysis has changed since this was generated.'));
    }

    public function test_users_without_a_snapshot_see_the_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Connect your accounts and Mahafeth AI will explain your portfolio in plain language.'));
    }
}
