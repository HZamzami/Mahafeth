<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_landing_page_with_both_calls_to_action(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('From scattered portfolios to one investment vision'))
            ->assertSee(__('Create account'))
            ->assertSee(__('Log in'))
            ->assertSee(__('Portfolio Health Score'))
            ->assertSee(__('From diagnosis to action'))
            ->assertSee('العربية');
    }

    public function test_the_landing_page_renders_in_arabic_with_rtl(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/');

        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('من محافظ متفرقة إلى رؤية استثمارية واحدة')
            ->assertSee('دراية المالية')
            ->assertSee('English');
    }

    public function test_the_deck_lists_all_six_feature_cards(): void
    {
        $this->get('/')
            ->assertSee(__('One unified portfolio'))
            ->assertSee(__('Hidden risks, revealed'))
            ->assertSee(__('AI that speaks your language'))
            ->assertSee(__('Shariah screening built in'))
            ->assertSee(__('Ready for what is next'));
    }

    public function test_authenticated_users_never_see_the_landing_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('dashboard'));
    }
}
