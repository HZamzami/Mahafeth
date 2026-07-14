<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_a_week_of_history_gets_the_digest(): void
    {
        Notification::fake();

        $user = $this->userWithHistory();

        $this->artisan('mahafeth:send-weekly-digest')
            ->expectsOutputToContain('Sent 1 weekly digests.')
            ->assertSuccessful();

        Notification::assertSentTo($user, WeeklyDigestNotification::class, function (WeeklyDigestNotification $digest): bool {
            return $digest->healthScore === 78
                && $digest->scoreChange === 6
                && abs($digest->valueChange - 5_000.0) < 1;
        });
    }

    public function test_the_digest_mail_narrates_the_score_move(): void
    {
        $user = $this->userWithHistory();

        $mail = (new WeeklyDigestNotification(78, 6, 105000.0, 5000.0, 2))->toMail($user);

        $this->assertSame(__('Your Mahafeth week in review'), $mail->subject);
        $this->assertStringContainsString(
            __('Your Portfolio Health Score rose :points points this week to :score.', ['points' => 6, 'score' => 78]),
            implode("\n", $mail->introLines),
        );
    }

    public function test_opted_out_users_are_skipped(): void
    {
        Notification::fake();

        $this->userWithHistory()->update(['notify_alerts' => false]);

        $this->artisan('mahafeth:send-weekly-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_users_without_week_old_history_are_skipped(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        PortfolioSnapshot::factory()->for($user)->create(['as_of' => today()]);

        $this->artisan('mahafeth:send-weekly-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_demo_accounts_never_get_the_digest(): void
    {
        Notification::fake();

        $this->userWithHistory(['email' => 'guest-abc@demo.mahafeth.test']);

        $this->artisan('mahafeth:send-weekly-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithHistory(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => today()->subDays(7),
            'health_score' => 72,
            'total_value' => 100000,
        ]);

        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => today(),
            'health_score' => 78,
            'total_value' => 105000,
        ]);

        return $user;
    }
}
