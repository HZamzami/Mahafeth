<?php

use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    private const GAUGE_CIRCUMFERENCE = 628; // 2πr with r = 100

    /**
     * Re-run the analytics pipeline on demand.
     */
    public function refresh(PortfolioAnalyzer $analyzer): void
    {
        $analyzer->analyze(Auth::user());

        $this->dispatch('portfolio-analyzed');
    }

    public function with(): array
    {
        $snapshot = Auth::user()->latestSnapshot();
        $score = $snapshot?->health_score;
        $metrics = $snapshot?->metrics;

        return [
            'score' => $score,
            'dashoffset' => $score !== null ? round(self::GAUGE_CIRCUMFERENCE * (1 - $score / 100)) : self::GAUGE_CIRCUMFERENCE,
            'metrics' => $metrics,
        ];
    }
}; ?>

<div
    class="flex grow flex-col items-center rounded-xl border border-neutral-200 bg-white p-6 text-center dark:border-neutral-700 dark:bg-zinc-900">
    <div class="flex w-full items-center justify-between">
        <flux:heading size="lg">{{ __('Portfolio Health Score') }}</flux:heading>
        <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="refresh" wire:loading.attr="disabled"
            :tooltip="__('Refresh Analysis')" />
    </div>

    <div class="relative my-8 flex grow items-center justify-center">
        <svg class="size-56 -rotate-90" viewBox="0 0 224 224">
            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16" stroke-linecap="round"
                class="stroke-neutral-100 dark:stroke-zinc-800" />
            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16" stroke-linecap="round"
                stroke="url(#healthGradient)" stroke-dasharray="628" stroke-dashoffset="{{ $dashoffset }}"
                class="drop-shadow-[0_0_8px_rgba(59,130,246,0.4)]" />
            <defs>
                <linearGradient id="healthGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#93c5fd" />
                    <stop offset="100%" stop-color="#3b82f6" />
                </linearGradient>
            </defs>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            @if ($score !== null)
                <span class="text-6xl font-bold text-blue-600 dark:text-blue-300">{{ $score }}</span>
                <flux:text class="text-sm uppercase tracking-widest">{{ __('Overall Health') }}</flux:text>
            @else
                <span class="text-4xl font-bold text-neutral-300 dark:text-zinc-600">—</span>
                <flux:text class="mt-1 max-w-40 text-xs">{{ __('Complete your investor profile to unlock scoring') }}
                </flux:text>
            @endif
        </div>
    </div>

    <div class="grid w-full grid-cols-3 gap-4 border-t border-neutral-200 pt-6 dark:border-neutral-700">
        <div class="text-center">
            <flux:text class="mb-1 text-xs">{{ __('Effective Holdings') }}</flux:text>
            <flux:heading class="!text-emerald-600 dark:!text-emerald-400" dir="ltr">
                {{ isset($metrics['effective_holdings']) ? number_format($metrics['effective_holdings'], 1) : '—' }}
            </flux:heading>
        </div>
        <div class="border-x border-neutral-200 text-center dark:border-neutral-700">
            <flux:text class="mb-1 text-xs">{{ __('Sharpe Ratio') }}</flux:text>
            <flux:heading class="!text-amber-600 dark:!text-amber-400" dir="ltr">
                {{ isset($metrics['sharpe']) ? number_format($metrics['sharpe'], 2) : '—' }}</flux:heading>
        </div>
        <div class="text-center">
            <flux:text class="mb-1 text-xs">{{ __('Max Drawdown') }}</flux:text>
            <flux:heading class="!text-blue-600 dark:!text-blue-400" dir="ltr">
                {{ isset($metrics['max_drawdown']) ? Number::percentage($metrics['max_drawdown'] * 100, 1) : '—' }}
            </flux:heading>
        </div>
    </div>
</div>
