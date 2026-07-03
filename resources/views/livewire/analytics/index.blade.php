<?php

use App\Services\Analytics\CorrelationAnalyzer;
use App\Services\Analytics\CovarianceMatrixService;
use App\Services\Analytics\EfficientFrontierService;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use App\Services\Analytics\RiskDecomposer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Correlation, efficient frontier, and risk decomposition of the user's
     * unified portfolio over the trailing year, computed live.
     */
    public function with(): array
    {
        $data = app(PortfolioDataAssembler::class)->forUser(Auth::user(), now()->subYear());

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
            'frontier' => $frontier,
            'frontierPlot' => $this->frontierPlot($frontier),
            'weights' => $weights,
            'sectorContributions' => app(RiskDecomposer::class)->contributions($weights, $covariance, $sectors),
            'decomposition' => Auth::user()->latestSnapshot()?->metrics['risk_decomposition'] ?? null,
        ];
    }

    /**
     * Project the cloud, frontier, and markers into SVG plot coordinates.
     *
     * @param  array{cloud: list<array{risk: float, return: float}>, frontier: list<array{risk: float, return: float}>, tangency: array, current: array}  $frontier
     * @return array{cloud: list<array{x: float, y: float}>, path: string, current: array{x: float, y: float}, tangency: array{x: float, y: float}, axis: array}
     */
    private function frontierPlot(array $frontier): array
    {
        $points = [...$frontier['cloud'], ['risk' => $frontier['current']['risk'], 'return' => $frontier['current']['return']]];

        $risks = array_column($points, 'risk');
        $returns = array_column($points, 'return');

        [$minX, $maxX] = [min($risks), max($risks)];
        [$minY, $maxY] = [min($returns), max($returns)];

        $plot = ['left' => 46, 'top' => 12, 'width' => 340, 'height' => 200];

        $project = fn (array $point): array => [
            'x' => round($plot['left'] + ($maxX > $minX ? ($point['risk'] - $minX) / ($maxX - $minX) : 0.5) * $plot['width'], 1),
            'y' => round($plot['top'] + (1 - ($maxY > $minY ? ($point['return'] - $minY) / ($maxY - $minY) : 0.5)) * $plot['height'], 1),
        ];

        $cloud = [];
        foreach ($frontier['cloud'] as $index => $point) {
            if ($index % 10 === 0) {
                $cloud[] = $project($point);
            }
        }

        $path = '';
        foreach ($frontier['frontier'] as $index => $point) {
            $projected = $project($point);
            $path .= ($index === 0 ? 'M' : ' L').$projected['x'].','.$projected['y'];
        }

        return [
            'cloud' => $cloud,
            'path' => $path,
            'current' => $project(['risk' => $frontier['current']['risk'], 'return' => $frontier['current']['return']]),
            'tangency' => $project(['risk' => $frontier['tangency']['risk'], 'return' => $frontier['tangency']['return']]),
            'axis' => [
                'minX' => $minX, 'maxX' => $maxX,
                'minY' => $minY, 'maxY' => $maxY,
            ],
        ];
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
            class="flex items-center justify-center rounded-xl border border-neutral-200 bg-white p-16 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:text>{{ __('Connect at least two holdings to see correlation analytics.') }}</flux:text>
        </div>
    @else
        {{-- Efficient Frontier --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <div
                class="rounded-xl border border-neutral-200 bg-white p-5 lg:col-span-2 dark:border-neutral-700 dark:bg-zinc-900">
                <flux:heading size="lg">{{ __('Efficient Frontier') }}</flux:heading>
                <flux:text class="mb-4 mt-1 text-sm">
                    {{ __('Each dot is a possible allocation of your current assets. The green line is the efficient frontier; the gap between your portfolio and it is recoverable performance.') }}
                </flux:text>

                <svg class="w-full" viewBox="0 0 400 260" dir="ltr">
                    {{-- Cloud --}}
                    @foreach ($frontierPlot['cloud'] as $dot)
                        <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="1.6"
                            class="fill-blue-400/25 dark:fill-blue-300/20" />
                    @endforeach

                    {{-- Frontier --}}
                    <path d="{{ $frontierPlot['path'] }}" fill="none" stroke-width="2.5" stroke-linecap="round"
                        class="stroke-emerald-500 dark:stroke-emerald-400" />

                    {{-- Current portfolio --}}
                    <circle cx="{{ $frontierPlot['current']['x'] }}" cy="{{ $frontierPlot['current']['y'] }}" r="5.5"
                        class="fill-red-500 stroke-white dark:stroke-zinc-900" stroke-width="2" />

                    {{-- Tangency portfolio --}}
                    <circle cx="{{ $frontierPlot['tangency']['x'] }}" cy="{{ $frontierPlot['tangency']['y'] }}"
                        r="5.5" class="fill-emerald-500 stroke-white dark:stroke-zinc-900" stroke-width="2" />

                    {{-- Axes --}}
                    <text x="46" y="252" class="fill-neutral-400 text-[9px]">{{ round($frontierPlot['axis']['minX'] * 100) }}%</text>
                    <text x="376" y="252" text-anchor="end" class="fill-neutral-400 text-[9px]">{{ round($frontierPlot['axis']['maxX'] * 100) }}%</text>
                    <text x="200" y="252" text-anchor="middle" class="fill-neutral-400 text-[9px]">{{ __('Risk (volatility)') }}</text>
                    <text x="40" y="216" text-anchor="end" class="fill-neutral-400 text-[9px]">{{ round($frontierPlot['axis']['minY'] * 100) }}%</text>
                    <text x="40" y="18" text-anchor="end" class="fill-neutral-400 text-[9px]">{{ round($frontierPlot['axis']['maxY'] * 100) }}%</text>
                </svg>

                <div class="mt-2 flex items-center justify-center gap-6">
                    <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-red-500"></span>
                        <flux:text class="text-xs">{{ __('Your portfolio') }}</flux:text>
                    </span>
                    <span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500"></span>
                        <flux:text class="text-xs">{{ __('Optimal (max Sharpe)') }}</flux:text>
                    </span>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Efficiency Gap') }}
                    </flux:text>
                    <flux:heading size="xl" dir="ltr">+{{ number_format($frontier['efficiency_gap'], 2) }}</flux:heading>
                    <flux:text class="mt-2 text-xs">
                        {{ __('Sharpe ratio improvement available at the optimal allocation.') }}</flux:text>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
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
                    class="grow rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-widest">
                        {{ __('Suggested Allocation') }}</flux:text>
                    <div class="space-y-1.5">
                        @foreach (collect($frontier['tangency']['weights'])->sortDesc()->take(5) as $symbol => $weight)
                            @php($delta = $weight - ($weights[$symbol] ?? 0))
                            <div class="flex items-center justify-between gap-2">
                                <flux:text class="text-sm">{{ $symbol }}</flux:text>
                                <span class="flex items-center gap-2" dir="ltr">
                                    <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                        {{ number_format($weight * 100, 1) }}%</flux:text>
                                    <flux:badge size="sm" :color="$delta >= 0 ? 'emerald' : 'red'" inset="top bottom">
                                        {{ ($delta >= 0 ? '+' : '').number_format($delta * 100, 1) }}</flux:badge>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Risk Decomposition --}}
        <div class="grid gap-4 md:grid-cols-2">
            @if ($decomposition !== null)
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ __('Risk Decomposition') }}</flux:heading>
                    <flux:text class="mb-4 mt-1 text-sm">
                        {{ __('Systematic risk follows the market and cannot be diversified away; unsystematic risk can.') }}
                    </flux:text>
                    <div class="flex h-3 w-full overflow-hidden rounded-full" dir="ltr">
                        <div class="bg-blue-500 dark:bg-blue-400"
                            style="width: {{ round($decomposition['systematic_share'] * 100) }}%"></div>
                        <div class="bg-amber-500 dark:bg-amber-400"
                            style="width: {{ round($decomposition['unsystematic_share'] * 100) }}%"></div>
                    </div>
                    <div class="mt-3 flex justify-between">
                        <span class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-blue-500 dark:bg-blue-400"></span>
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

            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
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
                                <div class="h-full bg-blue-500 dark:bg-blue-400"
                                    style="width: {{ min(100, round($share * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Correlation stats --}}
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Average Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($averageCorrelation, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Lower values generally indicate better diversification.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Stress Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($stressAverage, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Estimated average correlation during a market crisis, when assets tend to fall together.') }}
                </flux:text>
            </div>
        </div>

        {{-- Correlation Matrix --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
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
