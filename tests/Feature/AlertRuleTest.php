<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\AlertRule;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\PortfolioAlertNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Number;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AlertRuleTest extends TestCase
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

    public function test_the_settings_page_offers_custom_alerts(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/profile')
            ->assertOk()
            ->assertSee(__('Custom alerts'))
            ->assertSee(__('Add rule'));
    }

    public function test_saving_a_percent_rule_stores_the_threshold_as_a_fraction(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.alert-rules')
            ->call('edit')
            ->set('metric', 'volatility')
            ->set('threshold', '20')
            ->call('save')
            ->assertHasNoErrors();

        $rule = $user->alertRules()->sole();
        $this->assertSame('volatility', $rule->metric);
        $this->assertEqualsWithDelta(0.20, $rule->threshold, 1e-6);
        $this->assertTrue($rule->enabled);
    }

    public function test_a_health_score_rule_stores_whole_points(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.alert-rules')
            ->call('edit')
            ->set('metric', 'health_score')
            ->set('threshold', '60')
            ->call('save');

        $this->assertEqualsWithDelta(60.0, $user->alertRules()->sole()->threshold, 1e-6);
    }

    public function test_validation_rejects_unknown_metrics_and_out_of_range_thresholds(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('settings.alert-rules')
            ->set('metric', 'sharpe')
            ->set('threshold', '20')
            ->call('save')
            ->assertHasErrors(['metric']);

        Volt::test('settings.alert-rules')
            ->set('metric', 'volatility')
            ->set('threshold', '0')
            ->call('save')
            ->assertHasErrors(['threshold']);
    }

    public function test_rules_can_be_toggled_and_deleted(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('settings.alert-rules')->call('toggle', $rule->id);
        $this->assertFalse($rule->fresh()->enabled);

        Volt::test('settings.alert-rules')->call('delete', $rule->id);
        $this->assertNull($rule->fresh());
    }

    public function test_another_users_rules_are_neither_visible_nor_editable(): void
    {
        $other = User::factory()->create();
        $rule = AlertRule::factory()->create(['user_id' => $other->id, 'metric' => 'max_drawdown']);

        $this->actingAs(User::factory()->create());

        // Rule rows carry the "Alert above …" line; the metric picker in
        // the modal legitimately lists every metric label.
        Volt::test('settings.alert-rules')
            ->assertDontSee(__('Alert above :threshold', ['threshold' => '20.0%']));

        $this->expectException(ModelNotFoundException::class);
        Volt::test('settings.alert-rules')->call('delete', $rule->id);
    }

    public function test_a_crossed_rule_shows_on_the_dashboard_and_is_dismissible(): void
    {
        $user = $this->analyzedUser();
        // The derayah portfolio's volatility is well above 5%.
        AlertRule::factory()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.05]);
        $this->actingAs($user);

        $component = Volt::test('dashboard.alerts')
            ->assertSee(__('Custom alert', [], 'en'));

        $fingerprint = collect($component->viewData('alerts'))
            ->first(fn (array $alert): bool => str_contains($alert['text'], 'Custom alert'))['fingerprint'];

        $component->call('dismiss', $fingerprint);

        $this->assertContains($fingerprint, $user->fresh()->dismissed_alerts);
    }

    public function test_a_disabled_rule_raises_no_alert(): void
    {
        $user = $this->analyzedUser();
        AlertRule::factory()->disabled()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.05]);
        $this->actingAs($user);

        Volt::test('dashboard.alerts')->assertDontSee('Custom alert');
    }

    public function test_an_uncrossed_rule_raises_no_alert(): void
    {
        $user = $this->analyzedUser();
        AlertRule::factory()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.99]);
        $this->actingAs($user);

        Volt::test('dashboard.alerts')->assertDontSee('Custom alert');
    }

    public function test_a_newly_crossed_rule_triggers_the_notification(): void
    {
        Notification::fake();

        // Yesterday's volatility sat under the rule; today's real analysis
        // crosses it, so the alert is new and notifies.
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        app(SyncConnection::class)->handle($connection);

        PortfolioSnapshot::factory()->create([
            'user_id' => $user->id,
            'as_of' => now()->subDay()->toDateString(),
            'health_score' => 70,
            'metrics' => ['volatility' => 0.04],
        ]);

        AlertRule::factory()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.05]);

        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertSentTo($user, PortfolioAlertNotification::class, function (PortfolioAlertNotification $notification): bool {
            return collect($notification->newAlerts)
                ->contains(fn (array $alert): bool => str_starts_with($alert['identity'], 'custom:'));
        });
    }

    public function test_an_unchanged_rule_does_not_renotify(): void
    {
        Notification::fake();

        $user = $this->analyzedUser();
        AlertRule::factory()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.05]);

        // First run notifies; the second sees the same identity and stays quiet.
        $job = new AnalyzePortfolioJob($user);
        $job->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));
        Notification::fake();
        $job->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertNothingSent();
    }

    public function test_custom_alerts_render_in_arabic(): void
    {
        $user = $this->analyzedUser();
        AlertRule::factory()->create(['user_id' => $user->id, 'metric' => 'volatility', 'threshold' => 0.05]);
        $this->actingAs($user);

        app()->setLocale('ar');

        Volt::test('dashboard.alerts')
            ->assertSee(__('Custom alert: portfolio volatility of :value is above your :threshold limit.', [
                'value' => Number::percentage($user->latestSnapshot()->metrics['volatility'] * 100, 1),
                'threshold' => Number::percentage(5.0, 1),
            ]));
    }
}
