<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\ActivityEvent;
use App\Models\Connection;
use App\Models\Holding;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Fx\FxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AlertResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A synced, analyzed user whose tech-heavy Derayah portfolio already
     * trips the concentration alert.
     */
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

        return $user->fresh();
    }

    /**
     * Rebalance every holding to the same market value so no position can
     * trip the concentration threshold anymore.
     */
    private function equalizeHoldings(User $user): void
    {
        $fx = app(FxRateService::class);

        Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query->whereBelongsTo($user))
            ->get()
            ->each(function (Holding $holding) use ($fx): void {
                $close = (float) $holding->asset->priceHistories()->latest('date')->value('close');
                $value = $close * $fx->rate($holding->asset->currency);

                $holding->update(['quantity' => $value > 0 ? 10000 / $value : $holding->quantity]);
            });
    }

    public function test_a_cleared_alert_records_a_resolution_event(): void
    {
        $user = $this->analyzedUser();

        $this->assertContains(
            'concentration',
            array_column(app(AlertEvaluator::class)->forUser($user, $user->latestSnapshot()), 'identity'),
        );

        $this->equalizeHoldings($user);

        (new AnalyzePortfolioJob($user))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        $event = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::AlertResolved)
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('Concentration alert', $event->params['key']);
        $this->assertNotSame('', ActivityType::AlertResolved->label($event->params));
    }

    public function test_the_first_analysis_records_no_resolutions(): void
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        app(SyncConnection::class)->handle($connection);

        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        $this->assertSame(0, ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::AlertResolved)
            ->count());
    }

    public function test_an_unchanged_alert_is_not_celebrated(): void
    {
        $user = $this->analyzedUser();

        (new AnalyzePortfolioJob($user))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        $this->assertSame(0, ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::AlertResolved)
            ->count());
    }

    public function test_disabling_a_custom_rule_is_not_celebrated(): void
    {
        $user = $this->analyzedUser();

        $rule = $user->alertRules()->create([
            'metric' => 'volatility',
            'threshold' => 0.0001,
            'enabled' => true,
        ]);

        (new AnalyzePortfolioJob($user))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));
        $rule->update(['enabled' => false]);
        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        $celebrated = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::AlertResolved)
            ->get()
            ->filter(fn (ActivityEvent $event): bool => str_contains($event->params['key'] ?? '', 'Custom alert'));

        $this->assertCount(0, $celebrated);
    }

    public function test_the_dashboard_shows_and_dismisses_the_celebration(): void
    {
        $user = $this->analyzedUser();
        $this->equalizeHoldings($user);
        (new AnalyzePortfolioJob($user))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        $event = ActivityEvent::whereBelongsTo($user)->where('type', ActivityType::AlertResolved)->first();
        $this->assertNotNull($event);

        $this->actingAs($user);

        Volt::test('dashboard.alerts')
            ->assertSee(__('Nice work — resolved: :alert', [
                'alert' => __($event->params['key'], $event->params['params']),
            ]))
            ->call('dismiss', 'resolved:'.$event->id)
            ->assertDontSee(__('Nice work — resolved: :alert', [
                'alert' => __($event->params['key'], $event->params['params']),
            ]));
    }
}
