<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/settings/appearance')->assertRedirect('/login');
    }

    public function test_the_page_offers_the_three_theme_choices(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/appearance')
            ->assertOk()
            ->assertSee(__('Appearance'))
            ->assertSee(__('Light'))
            ->assertSee(__('Dark'))
            ->assertSee(__('System'));
    }
}
