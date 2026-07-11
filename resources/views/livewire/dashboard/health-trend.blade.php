<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    protected $listeners = ['portfolio-analyzed' => '$refresh'];

    /**
     * Health score history from stored snapshots.
     */
    public function with(): array
    {
        $points = Auth::user()->portfolioSnapshots()
            ->whereNotNull('health_score')
            ->orderBy('as_of')
            ->get(['as_of', 'health_score'])
            ->map(fn ($snapshot) => [
                'date' => $snapshot->as_of->toDateString(),
                'score' => $snapshot->health_score,
            ])
            ->all();

        return ['points' => $points];
    }
}; ?>

{{-- The root must always render for Livewire, but an empty div would still
     eat a flex gap in the dashboard column, so collapse it when hidden. --}}
<div @class(['hidden' => count($points) < 2])>
    @if (count($points) >= 2)
        <div
            class="relative shrink-0 overflow-hidden card p-5">
            <flux:heading class="mb-4" size="lg">{{ __('Health Trend') }}</flux:heading>
            <flux:chart class="relative" dir="ltr" :value="$points">
                <flux:chart.summary class="mb-3 flex items-baseline gap-3">
                    <span class="text-2xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                        <flux:chart.summary.value field="score" />/100</span>
                    <flux:chart.summary.value class="text-xs text-zinc-400" field="date"
                        :format="['month' => 'short', 'day' => 'numeric']" />
                </flux:chart.summary>

                <div class="aspect-4/1 relative">
                <flux:chart.svg>
                    <flux:chart.line class="text-emerald-500 dark:text-emerald-400" curve="smooth" field="score" />
                    <flux:chart.point class="text-emerald-500 dark:text-emerald-400" field="score" />
                    <flux:chart.cursor />
                    <flux:chart.axis axis="x" field="date" tick-count="5"
                        :format="['month' => 'short', 'day' => 'numeric']">
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                </flux:chart.svg>
                </div>

                <flux:chart.tooltip class="max-w-44">
                    <flux:chart.tooltip.heading field="date" :format="['month' => 'short', 'day' => 'numeric', 'year' => 'numeric']" />
                    <flux:chart.tooltip.value :label="__('Health Score')" field="score" suffix="/100" />
                </flux:chart.tooltip>
            </flux:chart>
        </div>
    @endif
</div>
