<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PortfolioAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_a_subscription_persists_it_for_the_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->call('storePushSubscription', [
                'endpoint' => 'https://push.example/endpoint',
                'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
            ])
            ->assertSet('hasPushSubscription', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://push.example/endpoint',
        ]);
    }

    public function test_the_test_notification_goes_to_push_without_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->updatePushSubscription('https://push.example/endpoint', 'p256dh-key', 'auth-token');
        $this->actingAs($user);

        Volt::test('settings.profile')->call('sendTestNotification');

        Notification::assertSentTo($user, PortfolioAlertNotification::class, function (PortfolioAlertNotification $notification) use ($user): bool {
            return $notification->via($user->fresh()) === [WebPushChannel::class];
        });
    }

    public function test_the_profile_page_shows_the_push_section(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/settings/profile');

        $response->assertOk();
        $response->assertSee(__('Push notifications'));
        $response->assertSee(__('Enable on this device'));
    }
}
