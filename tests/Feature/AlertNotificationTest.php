<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\PortfolioAlertNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertNotificationTest extends TestCase
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

    public function test_no_notification_when_alerts_are_unchanged(): void
    {
        Notification::fake();

        $user = $this->analyzedUser();

        (new AnalyzePortfolioJob($user))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertNothingSent();
    }

    public function test_a_health_score_drop_triggers_the_notification(): void
    {
        Notification::fake();

        $user = $this->analyzedUser();

        // Fake a much healthier previous snapshot so the fresh analysis
        // registers as a drop beyond the threshold.
        $user->latestSnapshot()->update([
            'as_of' => now()->subDay()->toDateString(),
            'health_score' => 95,
        ]);

        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertSentTo($user, PortfolioAlertNotification::class, function (PortfolioAlertNotification $notification): bool {
            return $notification->scoreDrop !== null && $notification->scoreDrop >= 5;
        });
    }

    public function test_notifications_are_suppressed_when_the_user_opts_out(): void
    {
        Notification::fake();

        $user = $this->analyzedUser();
        $user->update(['notify_alerts' => false]);
        $user->latestSnapshot()->update(['as_of' => now()->subDay()->toDateString(), 'health_score' => 95]);

        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertNothingSent();
    }

    public function test_no_notification_on_the_first_ever_analysis(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        app(SyncConnection::class)->handle($connection);

        (new AnalyzePortfolioJob($user->fresh()))->handle(app(PortfolioAnalyzer::class), app(AlertEvaluator::class));

        Notification::assertNothingSent();
    }

    public function test_the_mail_renders_alert_lines_in_arabic_for_arabic_users(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $notification = new PortfolioAlertNotification([[
            'key' => 'Correlation alert: in a market crisis your assets would move together with an estimated correlation of :correlation.',
            'color' => 'amber',
            'params' => ['correlation' => '0.72'],
        ]], scoreDrop: 8);

        app()->setLocale($user->preferredLocale());

        $rendered = $notification->toMail($user);

        app()->setLocale('en');

        $this->assertStringContainsString('0.72', implode(' ', array_map(fn ($line) => (string) $line, $rendered->introLines)));
        $this->assertSame(__('Mahafeth: your portfolio needs attention', [], 'ar'), $rendered->subject);
    }
}
