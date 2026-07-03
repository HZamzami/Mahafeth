<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\EfficientFrontierService;
use Tests\TestCase;

class EfficientFrontierServiceTest extends TestCase
{
    private EfficientFrontierService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EfficientFrontierService;
    }

    /**
     * Two identical, uncorrelated assets: the analytical optimum is 50/50
     * with volatility σ/√2 ≈ 0.1414.
     */
    public function test_two_identical_uncorrelated_assets_are_split_evenly_at_the_tangency(): void
    {
        $result = $this->service->analyze(
            expectedReturns: ['A' => 0.10, 'B' => 0.10],
            covarianceMatrix: [
                'A' => ['A' => 0.04, 'B' => 0.0],
                'B' => ['A' => 0.0, 'B' => 0.04],
            ],
            currentWeights: ['A' => 1.0, 'B' => 0.0],
            riskFreeRate: 0.02,
            samples: 8000,
        );

        $this->assertEqualsWithDelta(0.5, $result['tangency']['weights']['A'], 0.05);
        $this->assertEqualsWithDelta(0.2 / sqrt(2), $result['tangency']['risk'], 0.005);
        $this->assertGreaterThan(0, $result['efficiency_gap']);
    }

    public function test_sampled_weights_are_long_only_and_sum_to_one(): void
    {
        $result = $this->service->analyze(
            expectedReturns: ['A' => 0.08, 'B' => 0.12, 'C' => 0.15],
            covarianceMatrix: [
                'A' => ['A' => 0.02, 'B' => 0.005, 'C' => 0.004],
                'B' => ['A' => 0.005, 'B' => 0.06, 'C' => 0.02],
                'C' => ['A' => 0.004, 'B' => 0.02, 'C' => 0.10],
            ],
            currentWeights: ['A' => 0.2, 'B' => 0.3, 'C' => 0.5],
            riskFreeRate: 0.02,
            samples: 2000,
        );

        $this->assertEqualsWithDelta(1.0, array_sum($result['tangency']['weights']), 1e-9);

        foreach ($result['tangency']['weights'] as $weight) {
            $this->assertGreaterThanOrEqual(0.0, $weight);
        }
    }

    public function test_the_tangency_sharpe_is_never_below_the_current_sharpe(): void
    {
        $result = $this->service->analyze(
            expectedReturns: ['A' => 0.08, 'B' => 0.12],
            covarianceMatrix: [
                'A' => ['A' => 0.02, 'B' => 0.01],
                'B' => ['A' => 0.01, 'B' => 0.05],
            ],
            currentWeights: ['A' => 0.6, 'B' => 0.4],
            riskFreeRate: 0.02,
            samples: 500,
        );

        $this->assertGreaterThanOrEqual($result['current']['sharpe'], $result['tangency']['sharpe']);
        $this->assertGreaterThanOrEqual(0.0, $result['efficiency_gap']);
    }

    public function test_the_frontier_traces_the_upper_boundary_of_the_cloud(): void
    {
        $result = $this->service->analyze(
            expectedReturns: ['A' => 0.06, 'B' => 0.12, 'C' => 0.18],
            covarianceMatrix: [
                'A' => ['A' => 0.01, 'B' => 0.002, 'C' => 0.001],
                'B' => ['A' => 0.002, 'B' => 0.05, 'C' => 0.01],
                'C' => ['A' => 0.001, 'B' => 0.01, 'C' => 0.12],
            ],
            currentWeights: ['A' => 0.4, 'B' => 0.3, 'C' => 0.3],
            samples: 3000,
        );

        $frontier = $result['frontier'];
        $this->assertNotEmpty($frontier);

        // Runs from the minimum-variance tip up to the maximum-return portfolio.
        $cloudRisks = array_column($result['cloud'], 'risk');
        $this->assertEqualsWithDelta(min($cloudRisks), $frontier[0]['risk'], 1e-9);
        $this->assertEqualsWithDelta(
            max(array_column($result['cloud'], 'return')),
            end($frontier)['return'],
            1e-9,
        );

        for ($i = 1; $i < count($frontier); $i++) {
            $this->assertGreaterThanOrEqual($frontier[$i - 1]['risk'], $frontier[$i]['risk']);
        }

        // The boundary's best return equals the cloud's best return.
        $this->assertEqualsWithDelta(
            max(array_column($result['cloud'], 'return')),
            max(array_column($frontier, 'return')),
            1e-9,
        );

        // A frontier is concave: slopes between consecutive points never increase.
        for ($i = 2; $i < count($frontier); $i++) {
            $previousSlope = ($frontier[$i - 1]['return'] - $frontier[$i - 2]['return'])
                / max(1e-12, $frontier[$i - 1]['risk'] - $frontier[$i - 2]['risk']);
            $slope = ($frontier[$i]['return'] - $frontier[$i - 1]['return'])
                / max(1e-12, $frontier[$i]['risk'] - $frontier[$i - 1]['risk']);

            $this->assertLessThanOrEqual($previousSlope + 1e-9, $slope);
        }

        // Every cloud point sits on or below the hull, never above it.
        foreach ($result['cloud'] as $point) {
            $boundary = $this->boundaryReturnAt($frontier, $point['risk']);
            $this->assertLessThanOrEqual($boundary + 1e-9, $point['return']);
        }
    }

    /**
     * Linear interpolation of the hull's return at a given risk.
     *
     * @param  list<array{risk: float, return: float}>  $frontier
     */
    private function boundaryReturnAt(array $frontier, float $risk): float
    {
        for ($i = 1; $i < count($frontier); $i++) {
            if ($risk <= $frontier[$i]['risk']) {
                $span = max(1e-12, $frontier[$i]['risk'] - $frontier[$i - 1]['risk']);
                $t = ($risk - $frontier[$i - 1]['risk']) / $span;

                return $frontier[$i - 1]['return'] + $t * ($frontier[$i]['return'] - $frontier[$i - 1]['return']);
            }
        }

        return end($frontier)['return'];
    }

    public function test_the_simulation_is_deterministic(): void
    {
        $arguments = [
            'expectedReturns' => ['A' => 0.08, 'B' => 0.12],
            'covarianceMatrix' => [
                'A' => ['A' => 0.02, 'B' => 0.01],
                'B' => ['A' => 0.01, 'B' => 0.05],
            ],
            'currentWeights' => ['A' => 0.5, 'B' => 0.5],
            'riskFreeRate' => 0.02,
            'samples' => 500,
        ];

        $this->assertSame(
            $this->service->analyze(...$arguments)['tangency'],
            $this->service->analyze(...$arguments)['tangency'],
        );
    }
}
