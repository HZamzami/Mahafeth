<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_renders_every_section_for_an_analyzed_user(): void
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

        $this->actingAs($user)
            ->get('/report')
            ->assertOk()
            ->assertSee(__('Portfolio Report'))
            ->assertSee(__('Key Metrics'))
            ->assertSee(__('Shariah Compliance'))
            ->assertSee(__('Holdings'))
            ->assertSee(__('Asset Allocation'))
            ->assertSee('AAPL');
    }

    public function test_the_report_shows_the_connect_prompt_for_a_user_without_data(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/report')
            ->assertOk()
            ->assertSee(__('Connect your accounts and run an analysis to build your report.'))
            ->assertSee(__('Connect accounts'));
    }
}
