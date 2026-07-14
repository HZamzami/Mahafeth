<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SessionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/settings/sessions')->assertRedirect('/login');
    }

    public function test_the_page_lists_the_users_sessions_with_device_labels(): void
    {
        $user = User::factory()->create();
        $this->seedSession($user, 'other-session', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) CriOS/120.0');

        $this->actingAs($user);

        Volt::test('settings.sessions')
            ->assertSee(__('Sessions'))
            ->assertSee('iPhone · Chrome')
            ->assertSee('10.0.0.9');
    }

    public function test_other_devices_can_be_signed_out_with_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->seedSession($user, 'other-session', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');

        $this->actingAs($user);
        $currentId = session()->getId();
        $this->seedSession($user, $currentId, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0');

        Volt::test('settings.sessions')
            ->set('password', 'password')
            ->call('logoutOtherDevices')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
    }

    public function test_a_wrong_password_signs_nothing_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->seedSession($user, 'other-session', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0');

        $this->actingAs($user);

        Volt::test('settings.sessions')
            ->set('password', 'wrong-password')
            ->call('logoutOtherDevices')
            ->assertHasErrors(['password']);

        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    private function seedSession(User $user, string $id, string $userAgent): void
    {
        DB::table('sessions')->updateOrInsert(['id' => $id], [
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
