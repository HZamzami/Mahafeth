<?php

use App\Services\Analytics\GoalForecaster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    protected $listeners = ['portfolio-analyzed' => '$refresh'];

    /**
     * Monte Carlo goal forecasts from the latest snapshot: probability of
     * reaching each goal at the current mix vs the optimal (tangency) mix,
     * plus percentile bands for the fan chart of the nearest goal.
     */
    public function with(): array
    {
        $user = Auth::user();
        $snapshot = $user->latestSnapshot();
        $goals = $user->goals()->orderBy('target_date')->get();
        $metrics = $snapshot?->metrics ?? [];

        if ($snapshot === null || $goals->isEmpty() || ! isset($metrics['expected_return'], $metrics['volatility'])) {
            return ['forecasts' => [], 'chart' => [], 'hasGoals' => $goals->isNotEmpty()];
        }

        $forecaster = app(GoalForecaster::class);
        $tangency = $metrics['frontier']['tangency'] ?? null;
        $forecasts = [];
        $chart = [];

        foreach ($goals as $index => $goal) {
            $months = $goal->monthsRemaining();

            $current = $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $metrics['expected_return'],
                annualVolatility: (float) $metrics['volatility'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $optimal = $tangency === null ? null : $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $tangency['return'],
                annualVolatility: (float) $tangency['risk'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $forecasts[] = [
                'goal' => $goal,
                'months' => $months,
                'probability' => $current['probability'],
                'probabilityOptimal' => $optimal['probability'] ?? null,
                'median' => $current['final']['p50'],
            ];

            // Fan chart for the nearest goal only, downsampled to ~24 points.
            if ($index === 0 && $months > 0) {
                $step = max(1, intdiv($months, 24));

                foreach (range(0, $months - 1, $step) as $month) {
                    $chart[] = [
                        'date' => now()->addMonths($month + 1)->toDateString(),
                        'p10' => $current['bands']['p10'][$month],
                        'p50' => $current['bands']['p50'][$month],
                        'p90' => $current['bands']['p90'][$month],
                        'target' => $goal->target_amount,
                    ];
                }
            }
        }

        return ['forecasts' => $forecasts, 'chart' => $chart, 'hasGoals' => true];
    }
}; ?>

<div>
    @if ($forecasts !== [])
        <div class="card p-6">
            <flux:heading size="lg">{{ __('Goal Forecast') }}</flux:heading>

            @if ($chart !== [])
                <flux:chart class="aspect-2/1 relative mt-4" dir="ltr" :value="$chart">
                    <flux:chart.svg>
                        <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p10" stroke-dasharray="4 4" />
                        <flux:chart.line class="text-teal-600 dark:text-teal-400" curve="smooth" field="p50" />
                        <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p90" stroke-dasharray="4 4" />
                        <flux:chart.line class="text-emerald-500 dark:text-emerald-400" field="target" stroke-dasharray="2 3" />
                        <flux:chart.axis axis="x" field="date" tick-count="5" :format="['year' => 'numeric']">
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-count="4" :format="['notation' => 'compact']">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                </flux:chart>

                <div class="mt-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
                    <span class="flex items-center gap-1.5">
                        <span class="h-0.5 w-4 rounded bg-teal-600 dark:bg-teal-400"></span>
                        <flux:text class="text-xs">{{ __('Most likely path') }}</flux:text>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-4 border-t-2 border-dashed border-neutral-400 dark:border-zinc-500"></span>
                        <flux:text class="text-xs">{{ __('Better / worse cases') }}</flux:text>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-4 border-t-2 border-dotted border-emerald-500 dark:border-emerald-400"></span>
                        <flux:text class="text-xs">{{ __('Your goal') }}</flux:text>
                    </span>
                </div>
                <flux:text class="mt-1.5 text-center text-xs">
                    {{ __('1,000 simulated futures of your portfolio — 8 in 10 land between the dashed lines.') }}
                </flux:text>
            @endif

            <div class="mt-4 space-y-3">
                @foreach ($forecasts as $forecast)
                    <div class="rounded-lg border border-neutral-100 p-3 dark:border-zinc-800">
                        <div class="flex items-center justify-between gap-2">
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                {{ $forecast['goal']->name }}</flux:text>
                            <span class="flex items-center gap-1.5">
                                <flux:text class="text-xs">{{ __('Success odds') }}</flux:text>
                                <flux:badge size="sm" :color="$forecast['probability'] >= 0.7 ? 'emerald' : ($forecast['probability'] >= 0.4 ? 'amber' : 'red')" dir="ltr">
                                    {{ Number::percentage($forecast['probability'] * 100, 0) }}</flux:badge>
                            </span>
                        </div>
                        <flux:text class="mt-1 text-xs">
                            {{ __('⃁ :amount in :months months', ['amount' => Number::localizedAbbreviate($forecast['goal']->target_amount, 1), 'months' => $forecast['months']]) }}
                            @if ($forecast['probabilityOptimal'] !== null)
                                &bull; {{ __('at the optimal mix: :percent', ['percent' => Number::percentage($forecast['probabilityOptimal'] * 100, 0)]) }}
                            @endif
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif (! $hasGoals)
        <div class="card p-6">
            <flux:heading size="lg">{{ __('Goal Forecast') }}</flux:heading>
            <flux:text class="mt-2 text-sm">
                {{ __('Add a financial goal and Mahafeth will forecast your odds of reaching it.') }}</flux:text>
            <flux:button class="mt-3" size="sm" variant="primary" :href="route('investor-profile')" wire:navigate>
                {{ __('Add Goal') }}</flux:button>
        </div>
    @endif
</div>
