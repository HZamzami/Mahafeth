<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_page_offers_account_deletion(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/profile')
            ->assertOk()
            ->assertSee(__('Delete Account'));
    }

    public function test_a_wrong_password_rejects_the_deletion_and_keeps_the_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('password', 'not-the-password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_correct_password_deletes_the_account_and_logs_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertGuest();
    }
}
