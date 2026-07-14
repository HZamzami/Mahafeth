<?php

namespace Tests\Feature;

use App\Actions\ProvisionDemoAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_welcome_page_offers_the_demo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('Try the demo'))
            ->assertSee(route('demo.start'));
    }

    public function test_the_login_page_offers_the_demo(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(__('Try the demo'));
    }

    public function test_starting_the_demo_provisions_an_analyzed_account_and_signs_in(): void
    {
        $response = $this->post(route('demo.start'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = auth()->user();

        $this->assertStringEndsWith('@'.ProvisionDemoAccount::EMAIL_DOMAIN, $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->riskProfile);
        $this->assertSame(3, $user->connections()->count());
        $this->assertSame(3, $user->consents()->count());

        $snapshot = $user->latestSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertNotNull($snapshot->health_score);
        $this->assertGreaterThan(0, $snapshot->total_value);

        // Weekly backfill gives the trend chart history from minute one.
        $this->assertGreaterThan(5, $user->portfolioSnapshots()->count());
    }

    public function test_signed_in_users_are_redirected_without_a_new_account(): void
    {
        $this->actingAs(User::factory()->create());

        $before = User::count();

        $this->post(route('demo.start'))->assertRedirect();

        $this->assertSame($before, User::count());
    }

    public function test_the_demo_route_is_rate_limited(): void
    {
        // The throttle is under test, not provisioning: stub the action so
        // 20 requests do not build 20 real portfolios.
        $this->mock(ProvisionDemoAccount::class)
            ->shouldReceive('handle')
            ->andReturnUsing(fn () => User::factory()->create());

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('demo.start'));
            auth()->logout();
        }

        $this->post(route('demo.start'))->assertTooManyRequests();
    }

    public function test_getting_the_demo_url_redirects_home(): void
    {
        $this->get('/demo')->assertRedirect(route('home'));
    }

    public function test_provisioning_never_fetches_live_prices(): void
    {
        config(['services.twelvedata.key' => 'real-key-would-be-here']);
        Http::fake();

        $this->post(route('demo.start'))->assertRedirect(route('dashboard'));

        $this->assertNotNull(auth()->user()->latestSnapshot());
        Http::assertNothingSent();
    }

    public function test_the_purge_command_removes_only_stale_demo_accounts(): void
    {
        $stale = User::factory()->create([
            'email' => 'guest-old@'.ProvisionDemoAccount::EMAIL_DOMAIN,
            'created_at' => now()->subDays(3),
        ]);
        $fresh = User::factory()->create([
            'email' => 'guest-new@'.ProvisionDemoAccount::EMAIL_DOMAIN,
        ]);
        $real = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->artisan('mahafeth:purge-demo-accounts')
            ->expectsOutputToContain('Purged 1 demo accounts.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $stale->id]);
        $this->assertDatabaseHas('users', ['id' => $fresh->id]);
        $this->assertDatabaseHas('users', ['id' => $real->id]);
    }
}
