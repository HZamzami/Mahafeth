<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ConnectionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/connections')->assertRedirect('/login');
    }

    public function test_the_page_shows_your_accounts_and_available_demo_institutions(): void
    {
        Institution::factory()->create(['name' => 'Derayah Financial', 'provider' => 'fake']);

        $this->actingAs(User::factory()->create())
            ->get('/connections')
            ->assertOk()
            ->assertSee(__('Your accounts'))
            ->assertSee(__('Demo accounts'))
            ->assertSee('Derayah Financial');
    }

    public function test_creating_a_manual_account_makes_it_and_redirects_to_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $test = Volt::test('connections.index')
            ->set('createName', 'My Sahm')
            ->set('createType', 'brokerage')
            ->set('createCurrency', 'SAR')
            ->call('createAccount')
            ->assertHasNoErrors();

        $connection = $user->connections()->first();
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isManual());
        $this->assertSame('My Sahm', $connection->label);
        $this->assertCount(1, $connection->accounts);

        $test->assertRedirect(route('connections.account', $connection->accounts->first()));
    }

    public function test_approving_consent_creates_and_syncs_a_demo_connection(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'rain']);

        $this->actingAs($user);

        Volt::test('connections.consent', ['institution' => $institution])->call('approve');

        $connection = $user->connections()->first();
        $this->assertNotNull($connection);
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertCount(1, $connection->accounts);
        $this->assertCount(2, $connection->accounts->first()->holdings);
    }
}
