<?php

namespace Tests\Unit\Analytics;

use App\Models\RiskProfile;
use App\Services\Analytics\AlertEvaluator;
use Tests\TestCase;

class AlertEvaluatorTest extends TestCase
{
    private AlertEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new AlertEvaluator;
    }

    /**
     * @return array<string, mixed>
     */
    private function calmMetrics(): array
    {
        return [
            'largest_position' => ['name' => 'Saudi Aramco', 'weight' => 0.10],
            'volatility' => 0.12,
            'stress_correlation' => 0.30,
        ];
    }

    public function test_a_calm_portfolio_raises_no_alerts(): void
    {
        $this->assertSame([], $this->evaluator->evaluate($this->calmMetrics(), null));
    }

    public function test_null_metrics_raise_no_alerts(): void
    {
        $this->assertSame([], $this->evaluator->evaluate(null, null));
    }

    public function test_an_oversized_position_raises_the_concentration_alert(): void
    {
        $metrics = $this->calmMetrics();
        $metrics['largest_position'] = ['name' => 'Apple Inc.', 'weight' => 0.42];

        $alerts = $this->evaluator->evaluate($metrics, null);

        $this->assertCount(1, $alerts);
        $this->assertSame('red', $alerts[0]['color']);
        $this->assertSame('Apple Inc.', $alerts[0]['params']['name']);
    }

    public function test_volatility_above_target_raises_the_risk_alert(): void
    {
        $profile = RiskProfile::factory()->makeOne(['target_volatility' => 0.15]);

        $metrics = $this->calmMetrics();
        $metrics['volatility'] = 0.25;

        $alerts = $this->evaluator->evaluate($metrics, $profile);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Risk alert', $alerts[0]['key']);
    }

    public function test_high_stress_correlation_raises_the_correlation_alert(): void
    {
        $metrics = $this->calmMetrics();
        $metrics['stress_correlation'] = 0.72;

        $alerts = $this->evaluator->evaluate($metrics, null);

        $this->assertCount(1, $alerts);
        $this->assertSame('0.72', $alerts[0]['params']['correlation']);
    }
}
