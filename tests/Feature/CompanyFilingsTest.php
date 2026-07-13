<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Contracts\FilingProvider;
use App\Enums\AssetClass;
use App\Models\Asset;
use App\Models\CompanyFiling;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Filings\EdgarFilingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    /**
     * EDGAR fixtures: the ticker directory plus one submissions feed for
     * Apple containing a 10-Q, an 8-K, and an ignored Form 4.
     */
    private function fakeEdgar(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                '0' => ['cik_str' => 320193, 'ticker' => 'AAPL', 'title' => 'Apple Inc.'],
            ]),
            'data.sec.gov/submissions/*' => Http::response([
                'name' => 'Apple Inc.',
                'filings' => ['recent' => [
                    'form' => ['4', '10-Q', '8-K'],
                    'filingDate' => [now()->toDateString(), now()->subDays(3)->toDateString(), now()->subDays(10)->toDateString()],
                    'accessionNumber' => ['0001140361-26-000001', '0000320193-26-000002', '0000320193-26-000003'],
                    'primaryDocument' => ['xslF345X06/form4.xml', 'aapl-20260628.htm', 'aapl-8k.htm'],
                    'primaryDocDescription' => ['', 'Quarterly report', 'Current report'],
                ]],
            ]),
        ]);
    }

    public function test_the_edgar_provider_is_bound(): void
    {
        $this->assertInstanceOf(EdgarFilingProvider::class, app(FilingProvider::class));
    }

    public function test_the_provider_maps_edgar_submissions_to_filings(): void
    {
        Asset::factory()->create(['symbol' => 'AAPL', 'asset_class' => AssetClass::Equity]);
        $this->fakeEdgar();

        $filings = app(FilingProvider::class)->fetchLatest();

        // The Form 4 insider filing is ignored; 10-Q and 8-K survive.
        $this->assertCount(2, $filings);
        $this->assertSame('Apple Inc. files its quarterly report (Form 10-Q)', $filings[0]['headline']);
        $this->assertSame('quarterly_report', $filings[0]['type']);
        $this->assertSame('SEC EDGAR', $filings[0]['source']);
        $this->assertSame(
            'https://www.sec.gov/Archives/edgar/data/320193/000032019326000002/aapl-20260628.htm',
            $filings[0]['url'],
        );
        $this->assertSame('announcement', $filings[1]['type']);
    }

    public function test_saudi_and_non_equity_symbols_are_not_sent_to_edgar(): void
    {
        Asset::factory()->create(['symbol' => '2222.SR', 'asset_class' => AssetClass::Equity]);
        Asset::factory()->create(['symbol' => 'BTC', 'asset_class' => AssetClass::Crypto]);
        Http::fake();

        $this->assertSame([], app(FilingProvider::class)->fetchLatest());
        Http::assertNothingSent();
    }

    public function test_edgar_failures_return_no_filings(): void
    {
        Asset::factory()->create(['symbol' => 'AAPL', 'asset_class' => AssetClass::Equity]);
        Http::fake(['*' => Http::response(status: 500)]);

        $this->assertSame([], app(FilingProvider::class)->fetchLatest());
    }

    public function test_the_refresh_command_upserts_filings_and_prunes_stale_ones(): void
    {
        Asset::factory()->create(['symbol' => 'AAPL', 'asset_class' => AssetClass::Equity]);
        $this->fakeEdgar();
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
        // Derayah's fixture holds AAPL among others.
        $this->actingAs($this->analyzedUser());
        $this->fakeEdgar();
        $this->artisan('mahafeth:refresh-filings');

        CompanyFiling::factory()->create([
            'headline' => 'Unrelated company files its annual report',
            'symbol' => 'ZZZZ',
        ]);

        Volt::test('dashboard.company-filings')
            ->assertSee(__('Company Disclosures'))
            ->assertSee('Apple Inc. files its quarterly report')
            ->assertSee(__('Ask Mahafeth AI'))
            ->assertSeeHtml('data-flux-timeline')
            ->assertDontSee('Unrelated company');
    }

    public function test_the_arabic_locale_renders_the_arabic_headline(): void
    {
        $this->actingAs($this->analyzedUser());
        $this->fakeEdgar();
        $this->artisan('mahafeth:refresh-filings');

        app()->setLocale('ar');

        Volt::test('dashboard.company-filings')
            ->assertSee('تودع تقريرها الربعي');
    }

    public function test_users_without_holdings_see_the_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('dashboard.company-filings')
            ->assertSee(__('No recent disclosures from companies you hold.'));
    }
}
