<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page must render for every user state: a fresh account exercises
 * all empty states, an analyzed account exercises the full data paths,
 * and the Arabic pass catches locale regressions app-wide.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const STATIC_PAGES = [
        '/dashboard',
        '/advisor',
        '/connections',
        '/analytics',
        '/activity',
        '/holdings',
        '/explore',
        '/explore/UNKNOWN-SYMBOL',
        '/investor-profile',
        '/plan',
        '/whats-new',
        '/report',
        '/settings/profile',
        '/settings/password',
        '/settings/appearance',
    ];

    private function analyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user;
    }

    public function test_every_page_renders_for_a_fresh_user(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (self::STATIC_PAGES as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_every_page_renders_for_an_analyzed_user(): void
    {
        $this->actingAs($this->analyzedUser());

        foreach ([...self::STATIC_PAGES, '/holdings/AAPL', '/connections/consent/derayah'] as $page) {
            $this->get($page)->assertOk();
        }

        // Owned instruments redirect from Explore to their holding page.
        $this->get('/explore/AAPL')->assertRedirect(route('holdings.detail', 'AAPL'));
    }

    public function test_every_page_renders_for_an_analyzed_user_in_arabic(): void
    {
        $user = $this->analyzedUser();
        $user->update(['locale' => 'ar']);
        $this->actingAs($user);

        foreach ([...self::STATIC_PAGES, '/holdings/AAPL'] as $page) {
            $this->get($page)->assertOk();
        }
    }
}
