<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshPortfoliosTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_resyncs_api_connections_and_reanalyzes(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        $user->portfolioSnapshots()->delete();
        $connection->update(['last_synced_at' => now()->subDay()]);

        $this->artisan('mahafeth:refresh-portfolios')
            ->expectsOutputToContain('Refreshed 1 portfolios.')
            ->assertSuccessful();

        // Queue runs sync in tests, so the analysis lands immediately.
        $this->assertNotNull($user->fresh()->latestSnapshot());
        $this->assertTrue($connection->fresh()->last_synced_at->isToday());
    }

    public function test_import_connections_are_not_resynced(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->import()->create(['slug' => 'alinma-capital']);
        $connection = Connection::factory()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
            'source' => 'import',
            'last_synced_at' => now()->subDay(),
        ]);

        $this->artisan('mahafeth:refresh-portfolios')->assertSuccessful();

        $this->assertTrue($connection->fresh()->last_synced_at->isYesterday());
    }
}
