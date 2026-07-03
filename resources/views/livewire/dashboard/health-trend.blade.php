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

<div>
    @if (count($points) >= 2)
        <div
            class="relative shrink-0 overflow-hidden rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:heading class="mb-4" size="lg">{{ __('Health Trend') }}</flux:heading>
            <flux:chart class="aspect-4/1 relative" dir="ltr" :value="$points">
                <flux:chart.svg>
                    <flux:chart.line class="text-emerald-500 dark:text-emerald-400" curve="smooth" field="score" />
                    <flux:chart.axis axis="x" field="date" tick-count="5"
                        :format="['month' => 'short', 'day' => 'numeric']">
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                </flux:chart.svg>
            </flux:chart>
        </div>
    @endif
</div>
