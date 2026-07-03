<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Contracts\InsightGenerator;
use App\Models\AiInsight;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Insights\FakeInsightGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Volt::test('dashboard.ai-summary')
            ->call('generate')
            ->assertSee(__('Executive Summary'))
            ->assertSee(__('Action Plan'));

        $insight = AiInsight::first();

        $this->assertNotNull($insight);
        $this->assertSame('en', $insight->locale);
        $this->assertSame($user->latestSnapshot()->id, $insight->portfolio_snapshot_id);
        $this->assertStringContainsString('Apple', $insight->summary);
        $this->assertNotEmpty($insight->recommendations);
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

    public function test_users_without_a_snapshot_see_the_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.ai-summary')
            ->assertSee(__('Connect your accounts and Mahafeth AI will explain your portfolio in plain language.'));
    }
}
