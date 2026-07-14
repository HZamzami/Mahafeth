<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\TestCase;

class PasskeysPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_nudges_users_without_a_passkey(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(__('Sign in with Face ID or fingerprint next time? Add a passkey in under a minute.'));
    }

    public function test_the_dashboard_does_not_nudge_passkey_owners(): void
    {
        $user = User::factory()->create();
        $this->makePasskey($user, 'My phone');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(__('Sign in with Face ID or fingerprint next time? Add a passkey in under a minute.'));
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/settings/passkeys')->assertRedirect('/login');
    }

    public function test_the_passkeys_page_renders_with_the_empty_state(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/passkeys')
            ->assertOk()
            ->assertSee(__('Passkeys'))
            ->assertSee(__('No passkeys yet. Add one to sign in without your password.'));
    }

    public function test_the_page_lists_the_users_passkeys(): void
    {
        $user = User::factory()->create();
        $this->makePasskey($user, 'My phone');

        $this->actingAs($user);

        Volt::test('settings.passkeys')
            ->assertSee('My phone')
            ->assertDontSee(__('No passkeys yet. Add one to sign in without your password.'));
    }

    public function test_generating_register_options_requires_a_name(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('settings.passkeys')
            ->call('getRegisterOptions')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_register_options_carry_a_challenge_for_the_named_passkey(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('settings.passkeys')
            ->set('name', 'My phone')
            ->call('getRegisterOptions')
            ->assertHasNoErrors();

        $options = json_decode(session('passkey-registration-options'), true);
        $this->assertArrayHasKey('challenge', $options);
    }

    public function test_a_user_can_delete_their_own_passkey(): void
    {
        $user = User::factory()->create();
        $passkey = $this->makePasskey($user, 'My phone');

        $this->actingAs($user);

        Volt::test('settings.passkeys')
            ->call('deletePasskey', $passkey->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_a_user_cannot_delete_another_users_passkey(): void
    {
        $owner = User::factory()->create();
        $passkey = $this->makePasskey($owner, 'Owner key');

        $this->actingAs(User::factory()->create());

        Volt::test('settings.passkeys')->call('deletePasskey', $passkey->id);

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
    }

    private function makePasskey(User $user, string $name): Passkey
    {
        // The model mutator on `data` expects a full WebAuthn credential
        // source, which listing and deleting never touch; insert directly.
        $id = DB::table('passkeys')->insertGetId([
            'authenticatable_id' => $user->id,
            'name' => $name,
            'credential_id' => base64_encode($name),
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Passkey::query()->findOrFail($id);
    }
}
