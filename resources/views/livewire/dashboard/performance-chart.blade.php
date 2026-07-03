<?php

use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    private const MAX_POINTS = 60;

    /**
     * Trailing-year cumulative portfolio return, downsampled for the chart.
     */
    public function with(): array
    {
        $data = app(PortfolioDataAssembler::class)->forUser(Auth::user(), now()->subYear());

        $values = app(ReturnCalculator::class)->portfolioValueSeries($data['priceSeries'], $data['quantities']);

        return ['points' => $this->chartPoints($values)];
    }

    /**
     * @param  array<string, float>  $values  date => portfolio value
     * @return list<array{date: string, return: float}>
     */
    private function chartPoints(array $values): array
    {
        $first = reset($values);

        if ($first === false || $first <= 0) {
            return [];
        }

        $dates = array_keys($values);
        $step = max(1, (int) ceil(count($dates) / self::MAX_POINTS));
        $lastIndex = count($dates) - 1;

        $points = [];

        foreach ($dates as $index => $date) {
            if ($index % $step !== 0 && $index !== $lastIndex) {
                continue;
            }

            $points[] = [
                'date' => $date,
                'return' => round(($values[$date] / $first - 1) * 100, 2),
            ];
        }

        return $points;
    }
}; ?>

<div
    class="relative shrink-0 overflow-hidden rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
    <flux:heading class="mb-4" size="lg">{{ __('Total Return') }}</flux:heading>

    @if ($points !== [])
        <flux:chart class="aspect-3/1 relative" dir="ltr" :value="$points">
            <flux:chart.svg>
                <flux:chart.line class="text-blue-500 dark:text-blue-400" curve="smooth" field="return" />
                <flux:chart.area class="text-blue-500/10 dark:text-blue-400/10" curve="smooth" field="return" />
                <flux:chart.axis axis="x" field="date" tick-count="6"
                    :format="['month' => 'short', 'day' => 'numeric']">
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.axis axis="y">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
            </flux:chart.svg>
        </flux:chart>
    @else
        <div class="flex aspect-3/1 items-center justify-center">
            <flux:text class="text-sm">{{ __('Connect your accounts to see your portfolio performance.') }}</flux:text>
        </div>
    @endif
</div>
