<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_banner_renders_for_authenticated_users(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertSee(__('Install Mahafeth'));
        $response->assertSee('beforeinstallprompt');
    }

    public function test_install_banner_renders_on_login_page(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(__('Install Mahafeth'));
    }

    public function test_pages_link_the_web_app_manifest(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<link rel="manifest" href="/manifest.webmanifest" />', false);
        $response->assertSee('<meta name="theme-color" content="#141b28" />', false);
        $response->assertSee(__('Install Mahafeth'));
    }

    public function test_manifest_declares_installable_app(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('Mahafeth محافظ', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/dashboard', $manifest['start_url']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
    }

    public function test_service_worker_and_offline_page_exist(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        $serviceWorker = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('/offline.html', $serviceWorker);
    }
}
