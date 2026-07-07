<?php

use App\Services\Analytics\CorrelationAnalyzer;
use App\Services\Analytics\CovarianceMatrixService;
use App\Services\Analytics\EfficientFrontierService;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\RebalancePlanner;
use App\Services\Analytics\ReturnCalculator;
use App\Services\Analytics\RiskDecomposer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component {
    /**
     * Correlation, efficient frontier, and risk decomposition of the user's
     * unified portfolio over the trailing year, computed live.
     */
    public function with(): array
    {
        // Same IPS-driven lookback window as the analyzer.
        $windowYears = Auth::user()->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $data = app(PortfolioDataAssembler::class)->forUser(Auth::user(), now()->subYears($windowYears));

        if (count($data['priceSeries']) < 2) {
            return ['symbols' => []];
        }

        $returnCalculator = app(ReturnCalculator::class);
        $aligned = $returnCalculator->alignedLogReturns($data['priceSeries']);
        $covariance = app(CovarianceMatrixService::class)->matrix($aligned);

        $analyzer = app(CorrelationAnalyzer::class);
        $correlation = $analyzer->matrix($covariance);
        $averageCorrelation = $analyzer->averageCorrelation($correlation);

        // Current weights from the latest closes.
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

        return [
            'symbols' => array_keys($correlation),
            'correlation' => $correlation,
            'averageCorrelation' => $averageCorrelation,
            'stressAverage' => $analyzer->stressCorrelation($averageCorrelation),
            'firstFactorShare' => $analyzer->firstFactorShare($covariance),
            'frontier' => $frontier,
            'frontierPlot' => $this->frontierPlot($frontier),
            'weights' => $weights,
            'rebalanceOrders' => $this->rebalanceOrders($weights, $frontier['tangency']['weights'] ?? [], (float) $totalValue, $data),
            'sectorContributions' => app(RiskDecomposer::class)->contributions($weights, $covariance, $sectors),
            'decomposition' => Auth::user()->latestSnapshot()?->metrics['risk_decomposition'] ?? null,
        ];
    }

    /**
     * Concrete buy/sell orders that would move the portfolio to the
     * tangency allocation, honoring the investor's Shariah constraint.
     *
     * @return list<array{symbol: string, name: string, side: string, quantity: float, value: float, current_weight: float, target_weight: float}>
     */
    private function rebalanceOrders(array $weights, array $targetWeights, float $totalValue, array $data): array
    {
        if ($targetWeights === []) {
            return [];
        }

        return app(RebalancePlanner::class)->plan(
            currentWeights: $weights,
            targetWeights: $targetWeights,
            totalValue: $totalValue,
            quantities: $data['quantities'],
            assets: $data['assets'],
            shariahRequired: (bool) (Auth::user()->riskProfile?->constraints['shariah_required'] ?? false),
        );
    }

    /**
     * Download the rebalancing plan as CSV.
     */
    public function downloadRebalanceCsv(): StreamedResponse
    {
        $orders = $this->with()['rebalanceOrders'] ?? [];

        return response()->streamDownload(function () use ($orders): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['symbol', 'side', 'quantity', 'est_value_sar']);

            foreach ($orders as $order) {
                fputcsv($out, [$order['symbol'], $order['side'], $order['quantity'], $order['value']]);
            }

            fclose($out);
        }, 'mahafeth-rebalance-plan.csv');
    }

    /**
     * Project the cloud, frontier, and markers into SVG plot coordinates,
     * with nice rounded axis ticks so the chart reads like a real chart.
     *
     * @param  array{cloud: list<array{risk: float, return: float}>, frontier: list<array{risk: float, return: float}>, tangency: array, current: array}  $frontier
     * @return array{cloud: list<array{x: float, y: float}>, path: string, current: array{x: float, y: float}, tangency: array{x: float, y: float}, plot: array, xTicks: array, yTicks: array}
     */
    private function frontierPlot(array $frontier): array
    {
        $riskFree = (float) config('mahafeth.risk_free_rate');

        $points = [...$frontier['cloud'], ['risk' => $frontier['current']['risk'], 'return' => $frontier['current']['return']]];

        // Include the origin and the risk-free rate so the Capital Market
        // Line has its anchor on the y-axis.
        $xAxis = $this->niceAxis([0.0, ...array_column($points, 'risk')]);
        $yAxis = $this->niceAxis([$riskFree, ...array_column($points, 'return')]);

        $plot = ['left' => 52, 'top' => 14, 'width' => 334, 'height' => 196];

        $projectX = fn (float $risk): float => round($plot['left'] + ($risk - $xAxis['min']) / ($xAxis['max'] - $xAxis['min']) * $plot['width'], 1);
        $projectY = fn (float $return): float => round($plot['top'] + (1 - ($return - $yAxis['min']) / ($yAxis['max'] - $yAxis['min'])) * $plot['height'], 1);
        $project = fn (array $point): array => ['x' => $projectX($point['risk']), 'y' => $projectY($point['return'])];

        // Render every sample: the boundary line runs through actual cloud
        // points, so all of its support must be visible.
        $cloud = array_map($project, $frontier['cloud']);

        // Capital Market Line: E(R) = Rf + tangency Sharpe × σ, from the
        // risk-free anchor through the tangency portfolio, extended a bit.
        $cml = null;
        if ($frontier['tangency']['risk'] > 0) {
            $slope = ($frontier['tangency']['return'] - $riskFree) / $frontier['tangency']['risk'];
            $endRisk = min($xAxis['max'], $frontier['tangency']['risk'] * 1.35);

            $cml = [
                'x1' => $projectX(0.0),
                'y1' => $projectY($riskFree),
                'x2' => $projectX($endRisk),
                'y2' => $projectY($riskFree + $slope * $endRisk),
            ];
        }

        return [
            'cloud' => $cloud,
            'path' => $this->smoothPath(array_map($project, $frontier['frontier'])),
            'current' => $project(['risk' => $frontier['current']['risk'], 'return' => $frontier['current']['return']]),
            'tangency' => $project(['risk' => $frontier['tangency']['risk'], 'return' => $frontier['tangency']['return']]),
            'cml' => $cml,
            'plot' => $plot,
            'xTicks' => array_map(fn (float $tick): array => ['x' => $projectX($tick), 'label' => round($tick * 100).'%'], $xAxis['ticks']),
            'yTicks' => array_map(fn (float $tick): array => ['y' => $projectY($tick), 'label' => round($tick * 100).'%', 'zero' => abs($tick) < 1e-9], $yAxis['ticks']),
        ];
    }

    /**
     * Round an axis outward to a 1/2/5-step grid with ~5 ticks.
     *
     * @param  list<float>  $values
     * @return array{min: float, max: float, ticks: list<float>}
     */
    private function niceAxis(array $values): array
    {
        $min = min($values);
        $max = max($values);
        $range = max(1e-9, $max - $min);

        $rawStep = $range / 4;
        $magnitude = 10 ** floor(log10($rawStep));
        $step = collect([1, 2, 5, 10])
            ->map(fn (int $factor): float => $factor * $magnitude)
            ->first(fn (float $candidate): bool => $candidate >= $rawStep);

        $niceMin = floor($min / $step) * $step;
        $niceMax = ceil($max / $step) * $step;

        $ticks = [];
        for ($tick = $niceMin; $tick <= $niceMax + $step / 2; $tick += $step) {
            $ticks[] = $tick;
        }

        return ['min' => $niceMin, 'max' => $niceMax, 'ticks' => $ticks];
    }

    /**
     * Midpoint-quadratic smoothing: a gentle curve through the points
     * instead of jagged line segments.
     *
     * @param  list<array{x: float, y: float}>  $points
     */
    private function smoothPath(array $points): string
    {
        if (count($points) < 2) {
            return '';
        }

        $path = 'M'.$points[0]['x'].','.$points[0]['y'];

        for ($i = 1; $i < count($points) - 1; $i++) {
            $midX = round(($points[$i]['x'] + $points[$i + 1]['x']) / 2, 1);
            $midY = round(($points[$i]['y'] + $points[$i + 1]['y']) / 2, 1);

            $path .= ' Q'.$points[$i]['x'].','.$points[$i]['y'].' '.$midX.','.$midY;
        }

        $last = end($points);

        return $path.' L'.$last['x'].','.$last['y'];
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Portfolio Analytics') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('How your assets move together across all connected accounts.') }}
        </flux:text>
    </div>

    @if ($symbols === [])
        <div
            class="flex flex-col items-center justify-center gap-4 card p-16">
            <flux:text>{{ __('Connect at least two holdings to see correlation analytics.') }}</flux:text>
            <flux:button variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @else
        {{-- Efficient Frontier --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <div
                class="card p-5 lg:col-span-2">
                <flux:heading size="lg">{{ __('Efficient Frontier') }}</flux:heading>
                <flux:text class="mb-4 mt-1 text-sm">
                    {{ __('Each dot is a possible allocation of your current assets. The green line is the efficient frontier; the gap between your portfolio and it is recoverable performance.') }}
                </flux:text>

                @php($plot = $frontierPlot['plot'])
                <svg class="w-full" viewBox="0 0 400 262" dir="ltr">
                    {{-- Gridlines + tick labels --}}
                    @foreach ($frontierPlot['yTicks'] as $tick)
                        <line x1="{{ $plot['left'] }}" y1="{{ $tick['y'] }}"
                            x2="{{ $plot['left'] + $plot['width'] }}" y2="{{ $tick['y'] }}"
                            class="{{ $tick['zero'] ? 'stroke-neutral-300 dark:stroke-zinc-600' : 'stroke-neutral-100 dark:stroke-zinc-800' }}"
                            stroke-width="1" />
                        <text x="{{ $plot['left'] - 6 }}" y="{{ $tick['y'] + 3 }}" text-anchor="end"
                            class="fill-neutral-400 text-[9px]">{{ $tick['label'] }}</text>
                    @endforeach
                    @foreach ($frontierPlot['xTicks'] as $tick)
                        <line x1="{{ $tick['x'] }}" y1="{{ $plot['top'] }}" x2="{{ $tick['x'] }}"
                            y2="{{ $plot['top'] + $plot['height'] }}" class="stroke-neutral-100 dark:stroke-zinc-800"
                            stroke-width="1" />
                        <text x="{{ $tick['x'] }}" y="{{ $plot['top'] + $plot['height'] + 14 }}" text-anchor="middle"
                            class="fill-neutral-400 text-[9px]">{{ $tick['label'] }}</text>
                    @endforeach

                    {{-- Axis lines --}}
                    <line x1="{{ $plot['left'] }}" y1="{{ $plot['top'] }}" x2="{{ $plot['left'] }}"
                        y2="{{ $plot['top'] + $plot['height'] }}" class="stroke-neutral-300 dark:stroke-zinc-600"
                        stroke-width="1" />
                    <line x1="{{ $plot['left'] }}" y1="{{ $plot['top'] + $plot['height'] }}"
                        x2="{{ $plot['left'] + $plot['width'] }}" y2="{{ $plot['top'] + $plot['height'] }}"
                        class="stroke-neutral-300 dark:stroke-zinc-600" stroke-width="1" />

                    {{-- Axis titles --}}
                    <text x="{{ $plot['left'] + $plot['width'] / 2 }}" y="258" text-anchor="middle"
                        class="fill-neutral-400 text-[9px]">{{ __('Risk (volatility)') }}</text>
                    <text x="12" y="{{ $plot['top'] + $plot['height'] / 2 }}" text-anchor="middle"
                        transform="rotate(-90, 12, {{ $plot['top'] + $plot['height'] / 2 }})"
                        class="fill-neutral-400 text-[9px]">{{ __('Return (annualized, trailing)') }}</text>

                    {{-- Cloud --}}
                    @foreach ($frontierPlot['cloud'] as $dot)
                        <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="1.3"
                            class="fill-teal-500/25 dark:fill-teal-300/20" />
                    @endforeach

                    {{-- Capital Market Line --}}
                    @if ($frontierPlot['cml'] !== null)
                        <line x1="{{ $frontierPlot['cml']['x1'] }}" y1="{{ $frontierPlot['cml']['y1'] }}"
                            x2="{{ $frontierPlot['cml']['x2'] }}" y2="{{ $frontierPlot['cml']['y2'] }}"
                            stroke-width="1.5" stroke-dasharray="5 4"
                            class="stroke-teal-500 dark:stroke-teal-300" />
                    @endif

                    {{-- Frontier --}}
                    <path d="{{ $frontierPlot['path'] }}" fill="none" stroke-width="2.5" stroke-linecap="round"
                        class="stroke-emerald-500 dark:stroke-emerald-400" />

                    {{-- Current portfolio --}}
                    <circle cx="{{ $frontierPlot['current']['x'] }}" cy="{{ $frontierPlot['current']['y'] }}" r="5.5"
                        class="fill-red-500 stroke-white dark:stroke-zinc-900" stroke-width="2" />

                    {{-- Tangency portfolio --}}
                    <circle cx="{{ $frontierPlot['tangency']['x'] }}" cy="{{ $frontierPlot['tangency']['y'] }}"
                        r="5.5" class="fill-emerald-500 stroke-white dark:stroke-zinc-900" stroke-width="2" />
                </svg>

                <div class="mt-2 flex items-center justify-center gap-6">
                    <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-red-500"></span>
                        <flux:text class="text-xs">{{ __('Your portfolio') }}</flux:text>
                    </span>
                    <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500"></span>
                        <flux:text class="text-xs">{{ __('Optimal (max Sharpe)') }}</flux:text>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="h-0 w-5 border-t-2 border-dashed border-teal-500"></span>
                        <flux:text class="text-xs">{{ __('Capital Market Line') }}</flux:text>
                    </span>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                @php($signed = fn (float $value): string => ($value < 0 ? '−' : '').number_format(abs($value), 2))
                <div class="card p-5">
                    <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Efficiency Gap') }}
                    </flux:text>
                    <flux:heading size="xl" dir="ltr">{{ $signed($frontier['efficiency_gap']) }}</flux:heading>
                    <flux:text class="mt-2 text-xs">
                        {{ __('Your return per unit of risk (Sharpe ratio) would improve from :from to :to at the optimal mix.', [
                            'from' => $signed($frontier['current']['sharpe']),
                            'to' => $signed($frontier['tangency']['sharpe']),
                        ]) }}
                    </flux:text>
                </div>
                <div class="card p-5">
                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-widest">{{ __('Current vs Optimal') }}
                    </flux:text>
                    <div class="space-y-2" dir="ltr">
                        <div class="flex justify-between">
                            <flux:text class="text-sm">{{ __('Return') }}</flux:text>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                {{ number_format($frontier['current']['return'] * 100, 1) }}% →
                                {{ number_format($frontier['tangency']['return'] * 100, 1) }}%</flux:text>
                        </div>
                        <div class="flex justify-between">
                            <flux:text class="text-sm">{{ __('Risk') }}</flux:text>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                {{ number_format($frontier['current']['risk'] * 100, 1) }}% →
                                {{ number_format($frontier['tangency']['risk'] * 100, 1) }}%</flux:text>
                        </div>
                    </div>
                </div>
                <div
                    class="grow card p-5">
                    <flux:text class="mb-3 text-xs font-medium uppercase tracking-widest">
                        {{ __('Suggested Allocation') }}</flux:text>
                    <div class="grid grid-cols-[1fr_auto_auto] items-center gap-x-4 gap-y-2.5">
                        <flux:text class="text-[10px] uppercase tracking-wide">{{ __('Asset') }}</flux:text>
                        <flux:text class="text-end text-[10px] uppercase tracking-wide">{{ __('Target') }}</flux:text>
                        <flux:text class="text-center text-[10px] uppercase tracking-wide">{{ __('Change') }}
                        </flux:text>
                        @foreach (collect($frontier['tangency']['weights'])->sortDesc()->take(5) as $symbol => $weight)
                            @php($delta = $weight - ($weights[$symbol] ?? 0))
                            <flux:text class="text-sm">{{ $symbol }}</flux:text>
                            <flux:text class="text-end text-sm font-medium tabular-nums !text-zinc-800 dark:!text-white"
                                dir="ltr">{{ number_format($weight * 100, 1) }}%</flux:text>
                            <flux:badge class="w-16 justify-center tabular-nums" size="sm"
                                :color="$delta >= 0 ? 'emerald' : 'red'" inset="top bottom" dir="ltr">
                                {{ ($delta >= 0 ? '+' : '−').number_format(abs($delta) * 100, 1) }}%</flux:badge>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Rebalancing Plan --}}
        @if ($rebalanceOrders !== [])
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Rebalancing Plan') }}</flux:heading>
                        <flux:text class="mt-1 text-sm">
                            {{ __('The concrete orders that would move your portfolio to the optimal allocation.') }}
                        </flux:text>
                    </div>
                    <flux:button size="sm" variant="outline" icon="arrow-down-tray"
                        wire:click="downloadRebalanceCsv">{{ __('Download CSV') }}</flux:button>
                </div>
                <table class="mt-4 w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-neutral-400">
                            <th class="pb-2 text-start font-medium">{{ __('Asset') }}</th>
                            <th class="pb-2 text-center font-medium">{{ __('Action') }}</th>
                            <th class="pb-2 text-end font-medium">{{ __('Units') }}</th>
                            <th class="pb-2 text-end font-medium">{{ __('Est. Value') }} (⃁)</th>
                            <th class="pb-2 text-end font-medium">{{ __('Weight Change') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rebalanceOrders as $order)
                            <tr class="border-t border-neutral-100 dark:border-zinc-800">
                                <td class="py-1.5">
                                    <span class="font-medium text-zinc-800 dark:text-white">{{ $order['symbol'] }}</span>
                                    <span class="text-neutral-400"> · {{ $order['name'] }}</span>
                                </td>
                                <td class="py-1.5 text-center">
                                    <flux:badge size="sm" :color="$order['side'] === 'buy' ? 'emerald' : 'red'">
                                        {{ $order['side'] === 'buy' ? __('Buy') : __('Sell') }}</flux:badge>
                                </td>
                                <td class="py-1.5 text-end tabular-nums" dir="ltr">{{ number_format($order['quantity'], 2) }}</td>
                                <td class="py-1.5 text-end tabular-nums" dir="ltr">{{ number_format($order['value'], 0) }}</td>
                                <td class="py-1.5 text-end tabular-nums" dir="ltr">
                                    {{ number_format($order['current_weight'] * 100, 1) }}% → {{ number_format($order['target_weight'] * 100, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ((bool) (auth()->user()->riskProfile?->constraints['shariah_required'] ?? false))
                    <flux:text class="mt-3 text-xs">
                        {{ __('Buys of non-compliant assets are excluded and their budget reallocated to compliant holdings.') }}
                    </flux:text>
                @endif
            </div>
        @endif

        {{-- Risk Decomposition --}}
        <div class="grid gap-4 md:grid-cols-2">
            @if ($decomposition !== null)
                <div class="card p-5">
                    <flux:heading size="lg">{{ __('Risk Decomposition') }}</flux:heading>
                    <flux:text class="mb-4 mt-1 text-sm">
                        {{ __('Systematic risk follows the market and cannot be diversified away; unsystematic risk can.') }}
                    </flux:text>
                    <div class="flex h-3 w-full overflow-hidden rounded-full" dir="ltr">
                        <div class="bg-teal-600 dark:bg-teal-400"
                            style="width: {{ round($decomposition['systematic_share'] * 100) }}%"></div>
                        <div class="bg-amber-500 dark:bg-amber-400"
                            style="width: {{ round($decomposition['unsystematic_share'] * 100) }}%"></div>
                    </div>
                    <div class="mt-3 flex justify-between">
                        <span class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-teal-600 dark:bg-teal-400"></span>
                            <flux:text class="text-xs">
                                {{ __('Systematic :percent', ['percent' => round($decomposition['systematic_share'] * 100).'%']) }}
                            </flux:text>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-amber-500 dark:bg-amber-400"></span>
                            <flux:text class="text-xs">
                                {{ __('Unsystematic :percent', ['percent' => round($decomposition['unsystematic_share'] * 100).'%']) }}
                            </flux:text>
                        </span>
                    </div>
                </div>
            @endif

            <div class="card p-5">
                <flux:heading size="lg">{{ __('Risk by Sector') }}</flux:heading>
                <flux:text class="mb-4 mt-1 text-sm">
                    {{ __('Share of total portfolio risk contributed by each sector.') }}</flux:text>
                <div class="space-y-3">
                    @foreach ($sectorContributions as $sector => $share)
                        <div>
                            <div class="mb-1 flex justify-between">
                                <flux:text class="text-sm">{{ __($sector) }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ number_format($share * 100, 1) }}%</flux:text>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
                                <div class="h-full bg-teal-600 dark:bg-teal-400"
                                    style="width: {{ min(100, round($share * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Correlation stats --}}
        <div class="grid gap-4 md:grid-cols-3">
            <div class="card p-5">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Average Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($averageCorrelation, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Lower values generally indicate better diversification.') }}</flux:text>
            </div>
            <div class="card p-5">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Stress Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($stressAverage, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Estimated average correlation during a market crisis, when assets tend to fall together.') }}
                </flux:text>
            </div>
            <div class="card p-5">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Hidden Factor (PCA)') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($firstFactorShare * 100, 0) }}%</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Share of total variance driven by a single common factor. High values mean the portfolio is one big bet.') }}
                </flux:text>
            </div>
        </div>

        {{-- Correlation Matrix --}}
        <div class="card p-5">
            <flux:heading size="lg">{{ __('Correlation Matrix') }}</flux:heading>
            <flux:text class="mb-4 mt-1 text-sm">
                {{ __('Values near 1 mean two assets move together; near 0, independently; below 0, in opposite directions.') }}
            </flux:text>

            <div class="overflow-x-auto" dir="ltr">
                <table class="w-full border-separate border-spacing-1 text-center text-xs">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($symbols as $symbol)
                                <th class="p-1 font-medium text-neutral-500 dark:text-neutral-400">{{ $symbol }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($symbols as $row)
                            <tr>
                                <th class="p-1 text-start font-medium text-neutral-500 dark:text-neutral-400">
                                    {{ $row }}</th>
                                @foreach ($symbols as $column)
                                    @php($value = $correlation[$row][$column])
                                    <td class="rounded-md p-2 tabular-nums {{ $value >= 0.65 ? 'text-white' : 'text-zinc-800 dark:text-white' }}"
                                        style="background-color: {{ $value >= 0 ? 'rgba(59, 130, 246, '.round(min(1, $value) * 0.85, 2).')' : 'rgba(239, 68, 68, '.round(min(1, -$value) * 0.85, 2).')' }}">
                                        {{ number_format($value, 2) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
