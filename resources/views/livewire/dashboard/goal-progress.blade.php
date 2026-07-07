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
                        'month' => $month + 1,
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
        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ __('Goal Forecast') }}</flux:heading>

            @if ($chart !== [])
                <flux:chart class="aspect-3/1 relative mt-4" dir="ltr" :value="$chart">
                    <flux:chart.svg>
                        <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p10" stroke-dasharray="4 4" />
                        <flux:chart.line class="text-blue-500 dark:text-blue-400" curve="smooth" field="p50" />
                        <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p90" stroke-dasharray="4 4" />
                        <flux:chart.line class="text-emerald-500 dark:text-emerald-400" field="target" stroke-dasharray="2 3" />
                        <flux:chart.axis axis="y">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                </flux:chart>
                <flux:text class="mt-1 text-xs">
                    {{ __('Projected value range (10th to 90th percentile) toward your nearest goal. The dotted green line is the target.') }}
                </flux:text>
            @endif

            <div class="mt-4 space-y-3">
                @foreach ($forecasts as $forecast)
                    <div class="rounded-lg border border-neutral-100 p-3 dark:border-zinc-800">
                        <div class="flex items-center justify-between">
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                {{ $forecast['goal']->name }}</flux:text>
                            <flux:badge size="sm" :color="$forecast['probability'] >= 0.7 ? 'emerald' : ($forecast['probability'] >= 0.4 ? 'amber' : 'red')" dir="ltr">
                                {{ Number::percentage($forecast['probability'] * 100, 0) }}</flux:badge>
                        </div>
                        <flux:text class="mt-1 text-xs">
                            {{ __(':amount ⃁ in :months months', ['amount' => Number::abbreviate($forecast['goal']->target_amount, 1), 'months' => $forecast['months']]) }}
                            @if ($forecast['probabilityOptimal'] !== null)
                                &bull; {{ __('at the optimal mix: :percent', ['percent' => Number::percentage($forecast['probabilityOptimal'] * 100, 0)]) }}
                            @endif
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif (! $hasGoals)
        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ __('Goal Forecast') }}</flux:heading>
            <flux:text class="mt-2 text-sm">
                {{ __('Add a financial goal and Mahafeth will forecast your odds of reaching it.') }}</flux:text>
            <flux:button class="mt-3" size="sm" variant="primary" :href="route('investor-profile')" wire:navigate>
                {{ __('Add Goal') }}</flux:button>
        </div>
    @endif
</div>
