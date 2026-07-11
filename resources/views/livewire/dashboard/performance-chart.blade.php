<?php

use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    private const MAX_POINTS = 60;

    /**
     * Cumulative portfolio return over the IPS-driven window, with the
     * comparison benchmarks (SPY, TASI) overlaid on the same scale.
     */
    public function with(): array
    {
        $windowYears = Auth::user()->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');
        $from = now()->subYears($windowYears);

        $assembler = app(PortfolioDataAssembler::class);
        $data = $assembler->forUser(Auth::user(), $from);

        $values = app(ReturnCalculator::class)->portfolioValueSeries($data['priceSeries'], $data['quantities']);
        $benchmarks = $assembler->benchmarkSeriesFor(config('mahafeth.comparison_benchmarks'), $from);

        return [
            'points' => $this->chartPoints($values, $benchmarks),
            'benchmarkSymbols' => array_keys($benchmarks),
        ];
    }

    /**
     * Downsampled rows of cumulative % return for the portfolio and each
     * benchmark, all rebased to zero at the window start.
     *
     * @param  array<string, float>  $values  date => portfolio value
     * @param  array<string, array<string, float>>  $benchmarks  symbol => [date => close]
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
        $step = max(1, (int) ceil(count($dates) / self::MAX_POINTS));
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
}; ?>

<div
    class="relative shrink-0 overflow-hidden card p-5">
    <flux:heading class="mb-4" size="lg">{{ __('Total Return') }}</flux:heading>

    @if ($points !== [])
        <flux:chart class="aspect-3/1 relative" dir="ltr" :value="$points">
            <flux:chart.svg>
                @foreach ($benchmarkSymbols as $symbol)
                    <flux:chart.line class="{{ $symbol === 'TASI' ? 'text-amber-400/70 dark:text-amber-300/60' : 'text-zinc-400/70 dark:text-zinc-500/70' }}"
                        curve="smooth" field="{{ $symbol }}" />
                @endforeach

                <flux:chart.line class="text-teal-600 dark:text-teal-400" curve="smooth" field="portfolio" />
                <flux:chart.area class="text-teal-600/10 dark:text-teal-400/10" curve="smooth" field="portfolio" />

                {{-- The window spans years, so ticks carry the year; "Jan 1"
                     three times in a row hid every year change. --}}
                <flux:chart.axis axis="x" field="date" tick-count="6"
                    :format="['month' => 'short', 'year' => 'numeric']">
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.axis axis="y">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
            </flux:chart.svg>
        </flux:chart>

        <div class="mt-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
            <span class="flex items-center gap-1.5">
                <span class="h-0.5 w-4 rounded bg-teal-600 dark:bg-teal-400"></span>
                <flux:text class="text-xs">{{ __('Your portfolio') }}</flux:text>
            </span>
            @foreach ($benchmarkSymbols as $symbol)
                <span class="flex items-center gap-1.5">
                    <span
                        class="h-0.5 w-4 rounded {{ $symbol === 'TASI' ? 'bg-amber-400' : 'bg-zinc-400' }}"></span>
                    <flux:text class="text-xs">
                        {{ $symbol === 'TASI' ? __('Saudi market (TASI)') : __('US market (SPY)') }}</flux:text>
                </span>
            @endforeach
        </div>
        <flux:text class="mt-1.5 text-center text-xs">
            {{ __('Growth in % since the start of the window — above the market lines means your portfolio beat them.') }}
        </flux:text>
    @else
        <div class="flex aspect-3/1 flex-col items-center justify-center gap-3">
            <flux:text class="text-sm">{{ __('Connect your accounts to see your portfolio performance.') }}</flux:text>
            <flux:button size="sm" variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @endif
</div>
