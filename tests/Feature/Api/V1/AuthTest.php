<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hamza Zamzami',
            'email' => 'hamza@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'iPhone 15 Pro',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'hamza@example.com');
        $this->assertNotEmpty($response->json('data.token'));

        $this->assertDatabaseHas('users', ['email' => 'hamza@example.com']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 15 Pro']);
    }

    public function test_registration_requires_a_unique_email(): void
    {
        $existing = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'email' => $existing->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Test User',
                'email' => "user{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'onemore@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(429);
    }

    public function test_a_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');
        $tokenId = (int) Str::before($token, '|');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // Each simulated request in this test shares one application instance,
        // and Laravel's auth guards cache their resolved user for the guard's
        // lifetime — force it to re-authenticate against the (now-deleted)
        // token instead of reusing the cached "logged in" result from above.
        Auth::forgetGuards();

        $this->getJson('/api/v1/dashboard', [
            'Authorization' => "Bearer {$token}",
        ])->assertUnauthorized();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
