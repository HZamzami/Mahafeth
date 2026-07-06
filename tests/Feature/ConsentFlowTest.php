<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Models\Connection;
use App\Models\Consent;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ConsentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_consent_page_shows_the_requested_scopes(): void
    {
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        $this->actingAs(User::factory()->create())
            ->get(route('connections.consent', $institution))
            ->assertOk()
            ->assertSee(__('Balances'))
            ->assertSee(__('Approve access'));
    }

    public function test_approving_creates_the_consent_and_syncs_the_connection(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        $this->actingAs($user);

        Volt::test('connections.consent', ['institution' => $institution])
            ->call('approve')
            ->assertRedirect(route('connections'));

        $consent = $user->consents()->firstOrFail();
        $connection = $user->connections()->firstOrFail();

        $this->assertSame(ConsentStatus::Active, $consent->status);
        $this->assertTrue($consent->isActive());
        $this->assertSame(config('mahafeth.consent_scopes'), $consent->scopes);
        $this->assertSame($connection->id, $consent->connection_id);
        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertGreaterThan(0, $connection->accounts()->count());
    }

    public function test_denying_persists_nothing(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        $this->actingAs($user);

        Volt::test('connections.consent', ['institution' => $institution])
            ->call('deny')
            ->assertRedirect(route('connections'));

        $this->assertSame(0, $user->consents()->count());
        $this->assertSame(0, $user->connections()->count());
    }

    public function test_import_institutions_have_no_consent_page(): void
    {
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);

        $this->actingAs(User::factory()->create())
            ->get(route('connections.consent', $institution))
            ->assertNotFound();
    }

    public function test_revoking_disconnects_and_marks_the_consent_revoked(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);

        $this->actingAs($user);
        Volt::test('connections.consent', ['institution' => $institution])->call('approve');

        $connection = $user->connections()->firstOrFail();

        Volt::test('connections.index')->call('disconnect', $connection->id);

        $this->assertSame(ConnectionStatus::Disconnected, $connection->fresh()->status);
        $this->assertSame(ConsentStatus::Revoked, $user->consents()->firstOrFail()->status);
        $this->assertNotNull($user->consents()->firstOrFail()->revoked_at);
    }

    public function test_syncing_without_an_active_consent_is_rejected(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id]);

        Consent::factory()->expired()->create([
            'user_id' => $user->id,
            'institution_id' => $connection->institution_id,
            'connection_id' => $connection->id,
        ]);

        $this->actingAs($user);

        $lastSynced = $connection->last_synced_at;

        Volt::test('connections.index')->call('sync', $connection->id);

        $this->assertSame($lastSynced?->toIso8601String(), $connection->fresh()->last_synced_at?->toIso8601String());
    }

    public function test_the_expiry_command_expires_consents_and_disconnects(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id]);

        $consent = Consent::factory()->expired()->create([
            'user_id' => $user->id,
            'institution_id' => $connection->institution_id,
            'connection_id' => $connection->id,
        ]);

        $this->artisan('mahafeth:expire-consents')
            ->expectsOutputToContain('Expired 1 consents.')
            ->assertSuccessful();

        $this->assertSame(ConsentStatus::Expired, $consent->fresh()->status);
        $this->assertSame(ConnectionStatus::Disconnected, $connection->fresh()->status);
    }
}
