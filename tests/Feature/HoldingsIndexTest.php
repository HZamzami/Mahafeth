<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class HoldingsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedUser(): User
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user;
    }

    public function test_the_holdings_list_shows_every_position_with_the_total(): void
    {
        $this->actingAs($this->analyzedUser());

        Volt::test('holdings.index')
            ->assertSee(__('Holdings'))
            ->assertSee(__('Total Portfolio'))
            ->assertSee('AAPL')
            ->assertSeeHtml('countUp')
            ->assertSeeHtml('stagger-children');
    }

    public function test_a_fresh_user_sees_the_connect_prompt(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('holdings.index')
            ->assertSee(__('No sources connected yet'))
            ->assertSee(__('Connect accounts'));
    }

    public function test_another_users_holdings_never_render(): void
    {
        $this->analyzedUser();

        $this->actingAs(User::factory()->create());

        Volt::test('holdings.index')->assertDontSee('AAPL');
    }
}
