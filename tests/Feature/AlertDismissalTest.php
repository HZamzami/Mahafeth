<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Analytics\AlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AlertDismissalTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_show_a_dismiss_control(): void
    {
        $this->actingAs($this->userWithConcentrationAlert());

        Volt::test('dashboard.alerts')
            ->assertSee('Concentration alert')
            ->assertSeeHtml('wire:click="dismiss(');
    }

    public function test_a_dismissed_alert_is_hidden_and_persisted(): void
    {
        $user = $this->userWithConcentrationAlert();
        $this->actingAs($user);

        $fingerprint = app(AlertEvaluator::class)
            ->evaluate($user->latestSnapshot()->metrics, null)[0]['fingerprint'];

        Volt::test('dashboard.alerts')
            ->call('dismiss', $fingerprint)
            ->assertDontSee('Concentration alert');

        $this->assertSame([$fingerprint], $user->fresh()->dismissed_alerts);

        Volt::test('dashboard.alerts')->assertDontSee('Concentration alert');
    }

    public function test_a_dismissed_alert_reappears_when_the_metric_changes(): void
    {
        $user = $this->userWithConcentrationAlert(weight: 0.40);
        $this->actingAs($user);

        $fingerprint = app(AlertEvaluator::class)
            ->evaluate($user->latestSnapshot()->metrics, null)[0]['fingerprint'];

        Volt::test('dashboard.alerts')->call('dismiss', $fingerprint);

        PortfolioSnapshot::factory()->for($user)->create([
            'as_of' => now()->addDay()->toDateString(),
            'metrics' => $this->metricsWithConcentration(0.55),
        ]);

        Volt::test('dashboard.alerts')->assertSee('Concentration alert');
    }

    public function test_dismissing_one_alert_leaves_the_others_visible(): void
    {
        $user = User::factory()->create();
        PortfolioSnapshot::factory()->for($user)->create([
            'metrics' => $this->metricsWithConcentration(0.40) + ['stress_correlation' => 0.72],
        ]);
        $this->actingAs($user);

        $alerts = app(AlertEvaluator::class)->evaluate($user->latestSnapshot()->metrics, null);
        $this->assertCount(2, $alerts);

        Volt::test('dashboard.alerts')
            ->call('dismiss', $alerts[0]['fingerprint'])
            ->assertDontSee('Concentration alert')
            ->assertSee('Correlation alert');
    }

    public function test_stale_fingerprints_are_pruned_on_dismiss(): void
    {
        $user = $this->userWithConcentrationAlert();
        $user->forceFill(['dismissed_alerts' => ['stale-fingerprint']])->save();
        $this->actingAs($user);

        $fingerprint = app(AlertEvaluator::class)
            ->evaluate($user->latestSnapshot()->metrics, null)[0]['fingerprint'];

        Volt::test('dashboard.alerts')->call('dismiss', $fingerprint);

        $this->assertSame([$fingerprint], $user->fresh()->dismissed_alerts);
    }

    private function userWithConcentrationAlert(float $weight = 0.40): User
    {
        $user = User::factory()->create();

        PortfolioSnapshot::factory()->for($user)->create([
            'metrics' => $this->metricsWithConcentration($weight),
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsWithConcentration(float $weight): array
    {
        return [
            'largest_position' => ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'weight' => $weight],
        ];
    }
}
