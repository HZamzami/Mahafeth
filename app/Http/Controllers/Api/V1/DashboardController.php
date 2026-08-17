<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DismissAlertRequest;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\DailyMoveAttributor;
use App\Services\Analytics\NetFlowCalculator;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $snapshot = $request->user()->latestSnapshot();

        if ($snapshot === null) {
            return response()->json(['data' => ['has_snapshot' => false]]);
        }

        $previous = $request->user()->portfolioSnapshots()
            ->where('as_of', '<', $snapshot->as_of)
            ->latest('as_of')
            ->first();

        return response()->json([
            'data' => (new DashboardResource($snapshot, $previous))->resolve($request),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json([
            'data' => ['message' => __('Your portfolio analysis has been queued.')],
        ], 202);
    }

    /**
     * Health score history from stored snapshots.
     */
    public function trend(Request $request): JsonResponse
    {
        $points = $request->user()->portfolioSnapshots()
            ->whereNotNull('health_score')
            ->orderBy('as_of')
            ->get(['as_of', 'health_score'])
            ->map(fn ($snapshot): array => [
                'date' => $snapshot->as_of->toDateString(),
                'score' => $snapshot->health_score,
            ]);

        return response()->json(['data' => ['points' => $points]]);
    }

    /**
     * Cumulative portfolio return over the IPS-driven window, with the
     * comparison benchmarks overlaid, and a deposits-vs-market growth split.
     */
    public function performance(Request $request): JsonResponse
    {
        $user = $request->user();
        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');
        $from = now()->subYears($windowYears);

        $assembler = app(PortfolioDataAssembler::class);
        $data = $assembler->forUser($user, $from);
        $values = app(ReturnCalculator::class)->portfolioValueSeries($data['priceSeries'], $data['quantities']);
        $benchmarks = $assembler->benchmarkSeriesFor(config('mahafeth.comparison_benchmarks'), $from);

        $points = $this->chartPoints($values, $benchmarks);

        return response()->json([
            'data' => [
                'points' => $points,
                'benchmark_symbols' => array_keys($benchmarks),
                'growth' => $this->growthSplit($user, $values, $from),
            ],
        ]);
    }

    /**
     * Threshold alerts derived from the latest snapshot, minus dismissed
     * ones, plus celebrations for alerts resolved in the past week.
     */
    public function alerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $dismissed = $user->dismissed_alerts ?? [];

        $visible = array_filter(
            [...$this->resolvedCelebrations($user), ...$this->activeAlerts($user)],
            fn (array $alert): bool => ! in_array($alert['fingerprint'], $dismissed, true),
        );

        return response()->json([
            'data' => array_map(fn (array $alert): array => [
                'color' => $alert['color'],
                'fingerprint' => $alert['fingerprint'],
                'text' => __($alert['key'], $alert['params']),
                'resolved' => str_starts_with($alert['fingerprint'], 'resolved:'),
            ], array_values($visible)),
        ]);
    }

    /**
     * Hide an alert until its underlying metric changes.
     */
    public function dismissAlert(DismissAlertRequest $request): JsonResponse
    {
        $user = $request->user();
        $active = [
            ...array_column($this->activeAlerts($user), 'fingerprint'),
            ...array_column($this->resolvedCelebrations($user), 'fingerprint'),
        ];

        $dismissed = array_values(array_unique(array_intersect(
            [...($user->dismissed_alerts ?? []), $request->validated('fingerprint')],
            $active,
        )));

        $user->forceFill(['dismissed_alerts' => $dismissed])->save();

        return response()->json(['data' => ['message' => __('Alert dismissed.')]]);
    }

    /**
     * Attribution of the move between the two most recent snapshots.
     */
    public function dailyMove(Request $request): JsonResponse
    {
        $snapshots = $request->user()->portfolioSnapshots()->orderByDesc('as_of')->limit(2)->get();
        $move = app(DailyMoveAttributor::class)->attribute($snapshots->first(), $snapshots->get(1));

        return response()->json(['data' => $move]);
    }

    /**
     * @return list<array{key: string, color: string, identity: string, fingerprint: string, params: array<string, string>}>
     */
    private function activeAlerts(User $user): array
    {
        return app(AlertEvaluator::class)->forUser($user, $user->latestSnapshot());
    }

    /**
     * @return list<array{key: string, color: string, fingerprint: string, params: array<string, string>}>
     */
    private function resolvedCelebrations(User $user): array
    {
        return ActivityEvent::whereBelongsTo($user)
            ->where('type', ActivityType::AlertResolved)
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->get()
            ->map(fn (ActivityEvent $event): array => [
                'key' => 'Nice work — resolved: :alert',
                'color' => 'emerald',
                'fingerprint' => 'resolved:'.$event->id,
                'params' => ['alert' => __($event->params['key'] ?? '', $event->params['params'] ?? [])],
            ])
            ->all();
    }

    /**
     * @param  array<string, float>  $values
     * @return array{net_deposits: float, market_growth: float, since: string}|null
     */
    private function growthSplit(User $user, array $values, CarbonInterface $from): ?array
    {
        $start = reset($values);
        $end = end($values);

        if ($start === false || count($values) < 2) {
            return null;
        }

        $flows = app(NetFlowCalculator::class)->flows($user, $from);

        return [
            'net_deposits' => $flows['net'],
            'market_growth' => round($end - $start - $flows['net'], 2),
            'since' => array_key_first($values),
        ];
    }

    private const MAX_PERFORMANCE_POINTS = 60;

    /**
     * @param  array<string, float>  $values
     * @param  array<string, array<string, float>>  $benchmarks
     * @return list<array<string, mixed>>
     */
    private function chartPoints(array $values, array $benchmarks): array
    {
        $first = reset($values);

        if ($first === false || $first <= 0) {
            return [];
        }

        $benchmarkFirsts = array_map(fn (array $series): float => (float) reset($series), $benchmarks);

        $dates = array_keys($values);
        $step = max(1, (int) ceil(count($dates) / self::MAX_PERFORMANCE_POINTS));
        $lastIndex = count($dates) - 1;

        $points = [];

        foreach ($dates as $index => $date) {
            if ($index % $step !== 0 && $index !== $lastIndex) {
                continue;
            }

            $point = [
                'date' => $date,
                'portfolio' => round(($values[$date] / $first - 1) * 100, 2),
            ];

            foreach ($benchmarks as $symbol => $series) {
                if (isset($series[$date]) && $benchmarkFirsts[$symbol] > 0) {
                    $point[$symbol] = round(($series[$date] / $benchmarkFirsts[$symbol] - 1) * 100, 2);
                }
            }

            $points[] = $point;
        }

        return $points;
    }
}
