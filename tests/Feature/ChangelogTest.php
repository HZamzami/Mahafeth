<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Changelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/whats-new')->assertRedirect('/login');
    }

    public function test_the_page_renders_release_groups_with_typed_entries(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/whats-new')
            ->assertOk()
            ->assertSee(__("What's New"))
            ->assertSee(__('Investment plans'))
            ->assertSee(__('The Explore page'))
            ->assertSee(__('New'))
            ->assertSee(__('Improved'))
            ->assertSee(__('Fixed'))
            // The unseen-dot stamp for the latest release.
            ->assertSee(Changelog::latestDate());
    }

    public function test_the_arabic_locale_renders_arabic_entries(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['locale' => 'ar'])
            ->get('/whats-new')
            ->assertOk()
            ->assertSee('الجديد في محافظ')
            ->assertSee('خطط الاستثمار');
    }

    public function test_the_user_menus_link_to_whats_new(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('whats-new'));
    }

    public function test_entries_are_valid_and_sorted_newest_first(): void
    {
        $entries = Changelog::entries();

        $this->assertNotEmpty($entries);

        $dates = array_column($entries, 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);

        foreach ($entries as $release) {
            // Throws if a date is malformed.
            Carbon::createFromFormat('Y-m-d', $release['date']);
            $this->assertNotEmpty($release['items']);

            foreach ($release['items'] as $item) {
                $this->assertContains($item['type'], ['new', 'improved', 'fixed']);
                $this->assertNotSame('', $item['title']);
                $this->assertNotSame('', $item['body']);
            }
        }
    }
}
