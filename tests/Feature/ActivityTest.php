<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\PortfolioSnapshot;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private function syncedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);

        return $user->fresh();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/activity')->assertRedirect('/login');
    }

    public function test_the_page_renders_the_three_tabs_with_empty_states(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/activity')
            ->assertOk()
            ->assertSee(__('Notifications'))
            ->assertSee(__('Portfolio Changes'))
            ->assertSee(__('Security & Consents'))
            ->assertSee(__('Risk alerts and score warnings will show up here as they are raised.'));
    }

    public function test_a_sync_records_a_portfolio_event_with_the_holdings_count(): void
    {
        $user = $this->syncedUser();

        $event = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::ConnectionSynced)
            ->first();

        $this->assertNotNull($event);
        $this->assertGreaterThan(0, $event->params['count']);

        $this->actingAs($user);

        Volt::test('activity.index')
            ->assertSee(__(':institution synced — :count holdings imported.', [
                'institution' => $event->params['institution'],
                'count' => $event->params['count'],
            ]));
    }

    public function test_score_changes_are_recorded_by_the_analyzer(): void
    {
        $user = $this->syncedUser();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        // A prior snapshot with a different score; the fresh analysis
        // records the movement.
        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => now()->subDay()->toDateString(),
            'health_score' => 10,
        ]);

        app(PortfolioAnalyzer::class)->analyze($user);

        $event = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::ScoreChanged)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(10, $event->params['from']);
    }

    public function test_logging_in_records_a_security_event_with_the_ip(): void
    {
        $user = User::factory()->create();

        Volt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login');

        $event = ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::LoggedIn)
            ->first();

        $this->assertNotNull($event);
        $this->assertNotSame('', $event->params['ip']);
    }

    public function test_approving_a_consent_records_a_security_event(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $this->actingAs($user);

        Volt::test('connections.consent', ['institution' => $institution])
            ->call('approve');

        $this->assertNotNull(ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::ConsentGranted)
            ->first());
    }

    public function test_the_feed_renders_in_arabic(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);
        ActivityEvent::record($user, ActivityType::PasswordChanged);

        $this->actingAs($user);
        app()->setLocale('ar');

        Volt::test('activity.index')
            ->assertSee('تم تغيير كلمة المرور.');
    }

    public function test_events_are_scoped_to_the_signed_in_user(): void
    {
        $stranger = User::factory()->create();
        ActivityEvent::record($stranger, ActivityType::PasswordChanged);

        $this->actingAs(User::factory()->create());

        Volt::test('activity.index')
            ->assertDontSee(__('Your password was changed.'));
    }
}
