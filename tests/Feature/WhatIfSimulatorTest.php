<?php

namespace Tests\Feature;

use App\Actions\SyncConnection;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Analytics\WhatIfSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WhatIfSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedUser(): User
    {
        $user = User::factory()->create();
        RiskProfile::factory()->balanced()->create(['user_id' => $user->id]);

        $institution = Institution::factory()->create(['slug' => 'derayah']);
        $connection = Connection::factory()->pending()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(SyncConnection::class)->handle($connection);
        app(PortfolioAnalyzer::class)->analyze($user->fresh());

        return $user->fresh();
    }

    public function test_buying_more_of_the_largest_position_worsens_concentration(): void
    {
        $user = $this->analyzedUser();
        $largest = $user->latestSnapshot()->metrics['largest_position']['symbol'];
        $value = (float) $user->latestSnapshot()->total_value;

        $result = app(WhatIfSimulator::class)->simulate($user, $largest, $value * 0.5);

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['deltas']['hhi']);
        $this->assertGreaterThan(0, $result['deltas']['largest_weight']);
        $this->assertLessThan(0, $result['deltas']['effective_holdings']);
        $this->assertNotNull($result['health_before']);
        $this->assertNotNull($result['health_after']);
    }

    public function test_selling_the_largest_position_improves_concentration(): void
    {
        $user = $this->analyzedUser();
        $largest = $user->latestSnapshot()->metrics['largest_position']['symbol'];
        $value = (float) $user->latestSnapshot()->total_value;

        $result = app(WhatIfSimulator::class)->simulate($user, $largest, $value * 0.2, sell: true);

        $this->assertNotNull($result);
        $this->assertLessThan(0, $result['quantity']);
        $this->assertLessThan(0, $result['deltas']['largest_weight']);
    }

    public function test_a_sell_is_capped_at_the_held_quantity(): void
    {
        $user = $this->analyzedUser();
        $largest = $user->latestSnapshot()->metrics['largest_position']['symbol'];
        $value = (float) $user->latestSnapshot()->total_value;

        // Trying to sell ten times the portfolio only liquidates the position.
        $result = app(WhatIfSimulator::class)->simulate($user, $largest, $value * 10, sell: true);

        $this->assertNotNull($result);
        $this->assertLessThan($result['before']['largest_weight'], $result['after']['largest_weight']);
        $this->assertGreaterThan(0, $result['after']['largest_weight']);
    }

    public function test_an_unknown_symbol_returns_null(): void
    {
        $user = $this->analyzedUser();

        $this->assertNull(app(WhatIfSimulator::class)->simulate($user, 'NOPE', 10000.0));
        $this->assertNull(app(WhatIfSimulator::class)->simulate($user, 'NOPE', 10000.0, sell: true));
    }

    public function test_a_user_without_holdings_returns_null(): void
    {
        $this->assertNull(app(WhatIfSimulator::class)->simulate(User::factory()->create(), '2222.SR', 10000.0));
    }

    public function test_the_component_simulates_and_renders_deltas(): void
    {
        $user = $this->analyzedUser();
        $largest = $user->latestSnapshot()->metrics['largest_position']['symbol'];

        $this->actingAs($user);

        Volt::test('instruments.what-if', ['symbol' => $largest, 'owned' => true])
            ->assertSee(__('What if?'))
            ->set('amount', '100000')
            ->set('side', 'buy')
            ->call('simulate')
            ->assertSee(__('Health Score'))
            ->assertSee(__('Largest position'));
    }

    public function test_the_component_shows_the_graceful_message_for_unknown_symbols(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('instruments.what-if', ['symbol' => 'NOPE'])
            ->set('amount', '10000')
            ->call('simulate')
            ->assertSee(__('Not enough price history to simulate this trade. Connect your accounts first, or try another instrument.'));
    }

    public function test_the_component_validates_the_amount(): void
    {
        $user = $this->analyzedUser();
        $this->actingAs($user);

        Volt::test('instruments.what-if', ['symbol' => '2222.SR'])
            ->set('amount', null)
            ->call('simulate')
            ->assertHasErrors('amount');
    }
}
