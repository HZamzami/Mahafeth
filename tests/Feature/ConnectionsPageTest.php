<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function test_users_can_see_the_available_institutions(): void
    {
        $institution = Institution::factory()->create(['name' => 'Derayah Financial']);

        $this->actingAs(User::factory()->create())
            ->get('/connections')
            ->assertOk()
            ->assertSee('Derayah Financial');
    }

    public function test_connecting_an_institution_creates_and_syncs_the_connection(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'rain']);

        $this->actingAs($user);

        Volt::test('connections.index')->call('connect', $institution->id);

        $connection = $user->connections()->first();
        $this->assertNotNull($connection);
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertCount(1, $connection->accounts);
        $this->assertCount(2, $connection->accounts->first()->holdings);
    }

    public function test_disconnecting_marks_the_connection_as_disconnected(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        Volt::test('connections.index')->call('disconnect', $connection->id);

        $this->assertSame(ConnectionStatus::Disconnected, $connection->refresh()->status);
    }

    public function test_users_cannot_disconnect_another_users_connection(): void
    {
        $connection = Connection::factory()->create();

        $this->actingAs(User::factory()->create());

        try {
            Volt::test('connections.index')->call('disconnect', $connection->id);
            $this->fail('Expected a ModelNotFoundException for a foreign connection.');
        } catch (ModelNotFoundException) {
            // Scoping the lookup to the authenticated user rejects foreign IDs.
        }

        $this->assertSame(ConnectionStatus::Connected, $connection->refresh()->status);
    }
}
