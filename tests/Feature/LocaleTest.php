<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_switch_the_locale_via_the_session(): void
    {
        $response = $this->from('/')->get('/locale/ar');

        $response->assertRedirect('/');
        $this->assertSame('ar', session('locale'));
    }

    public function test_switching_the_locale_persists_it_on_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/dashboard')->get('/locale/ar')->assertRedirect('/dashboard');

        $this->assertSame('ar', $user->fresh()->locale);
    }

    public function test_unsupported_locales_are_rejected(): void
    {
        $this->get('/locale/fr')->assertNotFound();

        $this->assertNull(session('locale'));
    }

    public function test_the_session_locale_is_applied_to_the_application(): void
    {
        $this->withSession(['locale' => 'ar'])->get('/');

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_the_user_locale_is_applied_when_no_session_locale_is_set(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $this->actingAs($user)->get('/dashboard');

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_arabic_pages_are_rendered_right_to_left(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('لوحة التحكم');
    }

    public function test_english_pages_are_rendered_left_to_right(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
    }
}
