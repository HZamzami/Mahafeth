<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Contracts\FilingProvider;
use App\Models\CompanyFiling;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Filings\CuratedFilingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CompanyFilingsTest extends TestCase
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

    public function test_the_curated_provider_is_bound(): void
    {
        $this->assertInstanceOf(CuratedFilingProvider::class, app(FilingProvider::class));
    }

    public function test_the_refresh_command_upserts_filings_and_prunes_stale_ones(): void
    {
        CompanyFiling::factory()->create(['published_at' => now()->subDays(120)]);

        $this->artisan('mahafeth:refresh-filings')->assertSuccessful();

        $this->assertSame(0, CompanyFiling::where('published_at', '<', now()->subDays(90))->count());
        $this->assertGreaterThan(0, CompanyFiling::count());

        $count = CompanyFiling::count();
        $this->artisan('mahafeth:refresh-filings')->assertSuccessful();

        $this->assertSame($count, CompanyFiling::count());
    }

    public function test_the_dashboard_card_shows_only_filings_for_held_symbols(): void
    {
        $this->artisan('mahafeth:refresh-filings');
        CompanyFiling::factory()->create([
            'headline' => 'Unrelated company files its annual report',
            'symbol' => 'ZZZZ',
        ]);

        // Derayah's fixture holds AAPL among others.
        $this->actingAs($this->analyzedUser());

        Volt::test('dashboard.company-filings')
            ->assertSee(__('Company Disclosures'))
            ->assertSee('Apple Inc. files Form 10-Q')
            ->assertSee(__('Ask Mahafeth AI'))
            ->assertDontSee('Unrelated company');
    }

    public function test_the_arabic_locale_renders_the_arabic_headline(): void
    {
        $this->artisan('mahafeth:refresh-filings');
        $this->actingAs($this->analyzedUser());

        app()->setLocale('ar');

        Volt::test('dashboard.company-filings')
            ->assertSee('آبل تودع تقرير الربع الثالث');
    }

    public function test_users_without_holdings_see_the_empty_state(): void
    {
        $this->artisan('mahafeth:refresh-filings');
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.company-filings')
            ->assertSee(__('No recent disclosures from companies you hold.'));
    }
}
