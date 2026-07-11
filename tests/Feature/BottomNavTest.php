<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bottom_tab_bar_renders_with_the_main_destinations(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('More'));
        $response->assertSee(route('analytics'));
        $response->assertSee(route('advisor'));
        $response->assertSee(route('connections'));
        $response->assertSee(route('report'));
        $response->assertSee(route('investor-profile'));
        $response->assertSeeHtml('pb-[env(safe-area-inset-bottom)]');
    }

    public function test_the_mobile_header_no_longer_shows_the_hamburger_toggle(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertDontSeeHtml('data-flux-sidebar-toggle');
    }
}
