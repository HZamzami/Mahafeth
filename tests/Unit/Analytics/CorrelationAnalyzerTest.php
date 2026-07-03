<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\CorrelationAnalyzer;
use App\Services\Analytics\CovarianceMatrixService;
use PHPUnit\Framework\TestCase;

class CorrelationAnalyzerTest extends TestCase
{
    private const DELTA = 1e-9;

    private CorrelationAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new CorrelationAnalyzer;
    }

    public function test_correlation_matrix_matches_the_hand_computed_value(): void
    {
        // Same fixture as CovarianceMatrixServiceTest: ρ = 0.000725 / 0.000875 = 29/35.
        $covariance = (new CovarianceMatrixService)->matrix([
            'X' => [0.01, 0.02, -0.01, 0.03],
            'Y' => [0.02, 0.01, 0.00, 0.04],
        ], annualize: false);

        $correlation = $this->analyzer->matrix($covariance);

        $this->assertEqualsWithDelta(29 / 35, $correlation['X']['Y'], self::DELTA);
        $this->assertSame(1.0, $correlation['X']['X']);
        $this->assertSame(1.0, $correlation['Y']['Y']);
    }

    public function test_average_correlation_is_the_mean_of_the_upper_triangle(): void
    {
        $matrix = [
            'A' => ['A' => 1.0, 'B' => 0.8, 'C' => 0.2],
            'B' => ['A' => 0.8, 'B' => 1.0, 'C' => 0.5],
            'C' => ['A' => 0.2, 'B' => 0.5, 'C' => 1.0],
        ];

        $this->assertEqualsWithDelta(0.5, $this->analyzer->averageCorrelation($matrix), self::DELTA);
    }

    public function test_average_correlation_of_a_single_asset_is_zero(): void
    {
        $this->assertSame(0.0, $this->analyzer->averageCorrelation(['A' => ['A' => 1.0]]));
    }

    public function test_stress_correlation_shifts_toward_one(): void
    {
        $this->assertEqualsWithDelta(0.65, $this->analyzer->stressCorrelation(0.5), self::DELTA);
        $this->assertEqualsWithDelta(1.0, $this->analyzer->stressCorrelation(1.0), self::DELTA);
    }

    public function test_stress_matrix_keeps_the_diagonal_at_one(): void
    {
        $stressed = $this->analyzer->stressMatrix([
            'A' => ['A' => 1.0, 'B' => 0.5],
            'B' => ['A' => 0.5, 'B' => 1.0],
        ]);

        $this->assertSame(1.0, $stressed['A']['A']);
        $this->assertEqualsWithDelta(0.65, $stressed['A']['B'], self::DELTA);
    }
}
