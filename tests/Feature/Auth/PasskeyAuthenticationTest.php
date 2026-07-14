<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_authentication_options_endpoint_returns_a_challenge(): void
    {
        $response = $this->get(route('passkeys.authentication_options'));

        $response->assertOk();
        $this->assertArrayHasKey('challenge', json_decode($response->getContent(), true));
    }

    public function test_an_invalid_passkey_response_redirects_back_with_an_error(): void
    {
        $response = $this->from(route('login'))->post(route('passkeys.login'), [
            'start_authentication_response' => '{"nonsense": true}',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('authenticatePasskey::message');
        $this->assertGuest();
    }

    public function test_the_passkey_endpoints_are_rate_limited(): void
    {
        RateLimiter::clear(sha1('10,1'.request()->ip()));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get(route('passkeys.authentication_options'))->assertOk();
        }

        $this->get(route('passkeys.authentication_options'))->assertTooManyRequests();
    }

    public function test_the_login_page_offers_passkey_sign_in(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Sign in with Face ID or fingerprint'))
            ->assertSee('username webauthn');
    }
}
