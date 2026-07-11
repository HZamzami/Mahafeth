<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Asset;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertSee('Derayah Financial')
            ->assertSeeHtml('sm:flex-row sm:items-center');
    }

    public function test_approving_consent_creates_and_syncs_the_connection(): void
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

    public function test_disconnecting_marks_the_connection_as_disconnected(): void
    {
        $user = User::factory()->create();
        $connection = Connection::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        Volt::test('connections.index')->call('disconnect', $connection->id);

        $this->assertSame(ConnectionStatus::Disconnected, $connection->refresh()->status);
    }

    public function test_importing_a_statement_creates_the_connection_and_holdings(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);

        $this->actingAs($user);

        Volt::test('connections.index')
            ->set('statement', UploadedFile::fake()->createWithContent(
                'holdings.csv',
                (string) file_get_contents(base_path('tests/fixtures/alinma-capital-holdings.csv')),
            ))
            ->call('import', $institution->id)
            ->assertHasNoErrors();

        $connection = $user->connections()->where('institution_id', $institution->id)->firstOrFail();

        $this->assertSame(ConnectionStatus::Connected, $connection->status);
        $this->assertSame('import', $connection->source);
        $this->assertCount(3, $connection->accounts->first()->holdings);
        $this->assertTrue(Asset::where('symbol', '1010.SR')->exists());
        $this->assertGreaterThan(0, Asset::where('symbol', '2222.SR')->first()->priceHistories()->count());
        $this->assertNotNull($user->latestSnapshot());
    }

    public function test_reimporting_replaces_the_previous_statement_holdings(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);

        $this->actingAs($user);

        $upload = fn (string $contents) => Volt::test('connections.index')
            ->set('statement', UploadedFile::fake()->createWithContent('holdings.csv', $contents))
            ->call('import', $institution->id)
            ->assertHasNoErrors();

        $upload("symbol,quantity,avg_cost\n2222.SR,800,8.10\n7010.SR,500,10.40");
        $upload("symbol,quantity,avg_cost\n2222.SR,900,8.20");

        $holdings = $user->connections()->firstOrFail()->accounts->first()->holdings;

        $this->assertCount(1, $holdings);
        $this->assertEqualsWithDelta(900.0, $holdings->first()->quantity, 1e-9);
    }

    public function test_a_statement_with_no_valid_rows_is_rejected(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);

        $this->actingAs($user);

        Volt::test('connections.index')
            ->set('statement', UploadedFile::fake()->createWithContent('holdings.csv', "symbol,quantity\n2222.SR,800"))
            ->call('import', $institution->id)
            ->assertHasErrors('statement');

        $this->assertSame(0, $user->connections()->count());
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
