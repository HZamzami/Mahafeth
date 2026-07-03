<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\CovarianceMatrixService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CovarianceMatrixServiceTest extends TestCase
{
    private const DELTA = 1e-9;

    /**
     * Hand-computed fixture:
     * X = [0.01, 0.02, -0.01, 0.03], mean 0.0125
     * Y = [0.02, 0.01, 0.00, 0.04], mean 0.0175
     * Sample covariance = 0.000725 / 3, sample variances = 0.000875 / 3 each.
     */
    private const X = [0.01, 0.02, -0.01, 0.03];

    private const Y = [0.02, 0.01, 0.00, 0.04];

    private const COV_XY = 0.000725 / 3;

    private const VAR = 0.000875 / 3;

    private CovarianceMatrixService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CovarianceMatrixService;
    }

    public function test_sample_covariance_matches_the_hand_computed_value(): void
    {
        $this->assertEqualsWithDelta(self::COV_XY, $this->service->covariance(self::X, self::Y), self::DELTA);
    }

    public function test_variance_is_the_covariance_of_a_series_with_itself(): void
    {
        $this->assertEqualsWithDelta(self::VAR, $this->service->variance(self::X), self::DELTA);
        $this->assertEqualsWithDelta(self::VAR, $this->service->variance(self::Y), self::DELTA);
    }

    public function test_the_matrix_has_variances_on_the_diagonal_and_is_symmetric(): void
    {
        $matrix = $this->service->matrix(['X' => self::X, 'Y' => self::Y], annualize: false);

        $this->assertEqualsWithDelta(self::VAR, $matrix['X']['X'], self::DELTA);
        $this->assertEqualsWithDelta(self::VAR, $matrix['Y']['Y'], self::DELTA);
        $this->assertEqualsWithDelta(self::COV_XY, $matrix['X']['Y'], self::DELTA);
        $this->assertSame($matrix['X']['Y'], $matrix['Y']['X']);
    }

    public function test_the_matrix_is_annualized_by_default(): void
    {
        $matrix = $this->service->matrix(['X' => self::X, 'Y' => self::Y]);

        $this->assertEqualsWithDelta(self::COV_XY * 252, $matrix['X']['Y'], self::DELTA);
    }

    public function test_misaligned_series_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->covariance([0.01, 0.02], [0.01]);
    }

    public function test_series_shorter_than_two_points_have_zero_covariance(): void
    {
        $this->assertSame(0.0, $this->service->covariance([0.01], [0.02]));
    }
}
