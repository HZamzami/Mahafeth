<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\CorrelationAnalyzer;
use App\Services\Analytics\CovarianceMatrixService;
use App\Services\Analytics\EfficientFrontierService;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\RebalancePlanner;
use App\Services\Analytics\ReturnCalculator;
use App\Services\Analytics\RiskDecomposer;
use App\Services\Analytics\StressScenarioAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = Cache::remember(
            $this->cacheKey($user),
            now()->addHours(6),
            fn (): array => $this->compute($user),
        );

        return response()->json(['data' => $data]);
    }

    public function rebalancePlan(Request $request): JsonResponse
    {
        $data = Cache::remember(
            $this->cacheKey($request->user()),
            now()->addHours(6),
            fn (): array => $this->compute($request->user()),
        );

        return response()->json(['data' => ['orders' => $data['rebalanceOrders'] ?? []]]);
    }

    public function stressTest(Request $request, StressScenarioAnalyzer $analyzer): JsonResponse
    {
        $scenarios = config('mahafeth.stress_scenarios');
        $key = $request->query('scenario', array_key_first($scenarios));

        if (! isset($scenarios[$key])) {
            return response()->json(['message' => __('Unknown scenario.')], 422);
        }

        $user = $request->user();
        $snapshot = $user->latestSnapshot();
        $weights = $snapshot?->metrics['weights'] ?? [];

        if ($snapshot === null || $weights === []) {
            return response()->json(['data' => null]);
        }

        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $assets = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears))['assets'];

        return response()->json([
            'data' => [
                'scenario' => $key,
                'label' => $scenarios[$key]['label'],
                ...$analyzer->apply($weights, $assets, $scenarios[$key]),
            ],
        ]);
    }

    private function cacheKey(User $user): string
    {
        return sprintf(
            'api:analytics:v1:%d:%s:%s:%s',
            $user->id,
            $user->latestSnapshot()?->id ?? 'none',
            $user->connections()->max('last_synced_at') ?? 'never',
            $user->riskProfile?->updated_at?->timestamp ?? 'none',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(User $user): array
    {
        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears));

        if (count($data['priceSeries']) < 2) {
            return ['symbols' => []];
        }

        $returnCalculator = app(ReturnCalculator::class);
        $aligned = $returnCalculator->alignedLogReturns($data['priceSeries']);
        $covariance = app(CovarianceMatrixService::class)->matrix($aligned);

        $correlationAnalyzer = app(CorrelationAnalyzer::class);
        $correlation = $correlationAnalyzer->matrix($covariance);
        $averageCorrelation = $correlationAnalyzer->averageCorrelation($correlation);

        $values = $returnCalculator->portfolioValueSeries($data['priceSeries'], $data['quantities']);
        $totalValue = end($values);
        $weights = [];
        foreach ($data['priceSeries'] as $symbol => $series) {
            $weights[$symbol] = $totalValue > 0 ? ($data['quantities'][$symbol] * end($series)) / $totalValue : 0.0;
        }

        $expectedReturns = array_map(fn (array $returns): float => $returnCalculator->annualizedReturn($returns), $aligned);

        $frontier = app(EfficientFrontierService::class)->analyze($expectedReturns, $covariance, $weights, samples: 3000);

        $sectors = [];
        foreach ($data['assets'] as $symbol => $asset) {
            if ($asset['sector'] !== null) {
                $sectors[$symbol] = $asset['sector'];
            }
        }

        $targetWeights = $frontier['recommended']['weights'] ?? [];

        return [
            'symbols' => array_keys($correlation),
            'correlation' => $correlation,
            'averageCorrelation' => $averageCorrelation,
            'stressAverage' => $correlationAnalyzer->stressCorrelation($averageCorrelation),
            'firstFactorShare' => $correlationAnalyzer->firstFactorShare($covariance),
            'frontier' => $frontier,
            'weights' => $weights,
            'rebalanceOrders' => $targetWeights === [] ? [] : app(RebalancePlanner::class)->plan(
                currentWeights: $weights,
                targetWeights: $targetWeights,
                totalValue: (float) $totalValue,
                quantities: $data['quantities'],
                assets: $data['assets'],
                shariahRequired: (bool) ($user->riskProfile?->constraints['shariah_required'] ?? false),
            ),
            'sectorContributions' => app(RiskDecomposer::class)->contributions($weights, $covariance, $sectors),
            'decomposition' => $user->latestSnapshot()?->metrics['risk_decomposition'] ?? null,
        ];
    }
}
