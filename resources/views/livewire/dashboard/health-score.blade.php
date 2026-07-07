<?php

use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
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

        return [
            'score' => $score,
            'scoreLabel' => $this->scoreLabel($score),
            'dashoffset' => $score !== null ? round(self::GAUGE_CIRCUMFERENCE * (1 - $score / 100)) : self::GAUGE_CIRCUMFERENCE,
            'components' => $snapshot?->component_scores,
            'hasSnapshot' => $snapshot !== null,
            'hasProfile' => Auth::user()->riskProfile !== null,
        ];
    }

    private function scoreLabel(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 80 => __('Excellent'),
            $score >= 65 => __('Strong'),
            $score >= 50 => __('Fair'),
            default => __('Needs Attention'),
        };
    }
}; ?>

<div
    class="flex grow flex-col items-center card p-6 text-center">
    <div class="flex w-full items-center justify-between">
        <flux:heading size="lg">{{ __('Portfolio Health Score') }}</flux:heading>
        @if ($hasSnapshot)
            <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="refresh"
                wire:loading.attr="disabled" :tooltip="__('Refresh Analysis')" />
        @endif
    </div>

    <div class="relative my-8 flex grow items-center justify-center">
        <svg class="size-56 -rotate-90" viewBox="0 0 224 224">
            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16" stroke-linecap="round"
                class="stroke-neutral-100 dark:stroke-zinc-800" />
            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16" stroke-linecap="round"
                stroke="url(#healthGradient)" stroke-dasharray="628" stroke-dashoffset="{{ $dashoffset }}"
                class="drop-shadow-[0_0_8px_rgba(15,118,110,0.35)]" />
            <defs>
                <linearGradient id="healthGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#5eead4" />
                    <stop offset="100%" stop-color="#0f766e" />
                </linearGradient>
            </defs>
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            @if ($score !== null)
                <span class="text-6xl font-bold text-teal-700 dark:text-teal-300">{{ $score }}</span>
                <flux:text class="text-sm uppercase tracking-widest">{{ $scoreLabel }}</flux:text>
            @elseif (! $hasProfile)
                <span class="text-4xl font-bold text-neutral-300 dark:text-zinc-600">—</span>
                <flux:text class="mt-1 max-w-40 text-xs">{{ __('Complete your investor profile to unlock scoring') }}
                </flux:text>
                <flux:button class="mt-3" size="xs" variant="primary" :href="route('investor-profile')"
                    wire:navigate>{{ __('Start') }}</flux:button>
            @else
                <span class="text-4xl font-bold text-neutral-300 dark:text-zinc-600">—</span>
                <flux:text class="mt-1 max-w-40 text-xs">{{ __('Refresh the analysis to compute your score') }}
                </flux:text>
            @endif
        </div>
    </div>

    <div class="grid w-full grid-cols-3 gap-4 border-t border-neutral-200 pt-6 dark:border-neutral-700">
        <div class="text-center">
            <flux:text class="mb-1 text-xs">{{ __('Diversification') }}</flux:text>
            <flux:heading class="!text-emerald-600 dark:!text-emerald-400" dir="ltr">
                {{ isset($components['diversification']) ? $components['diversification'].'/100' : '—' }}</flux:heading>
        </div>
        <div class="border-x border-neutral-200 text-center dark:border-neutral-700">
            <flux:text class="mb-1 text-xs">{{ __('Concentration') }}</flux:text>
            <flux:heading class="!text-amber-600 dark:!text-amber-400" dir="ltr">
                {{ isset($components['concentration']) ? $components['concentration'].'/100' : '—' }}</flux:heading>
        </div>
        <div class="text-center">
            <flux:text class="mb-1 text-xs">{{ __('Risk Alignment') }}</flux:text>
            <flux:heading class="!text-teal-700 dark:!text-teal-300" dir="ltr">
                {{ isset($components['risk_alignment']) ? $components['risk_alignment'].'/100' : '—' }}</flux:heading>
        </div>
    </div>

    <flux:modal.trigger name="health-methodology">
        <flux:button class="mt-4" size="xs" variant="ghost" icon="question-mark-circle">
            {{ __('How is this calculated?') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="health-methodology" class="md:w-[28rem]">
        <div class="space-y-4 text-start">
            <flux:heading size="lg">{{ __('How the Health Score works') }}</flux:heading>
            <flux:text class="text-sm">
                {{ __('The score is a weighted average of six components, each rated 0–100 where higher is always better:') }}
            </flux:text>
            <div class="space-y-2">
                @foreach ([
                    'diversification' => [__('Diversification'), __('How many truly independent positions you hold.')],
                    'risk_alignment' => [__('Risk Alignment'), __('How closely your volatility matches your investor profile target.')],
                    'correlation' => [__('Correlation'), __('Whether your assets move independently or as one bet.')],
                    'performance' => [__('Performance'), __('Risk-adjusted return (Sharpe, Sortino) and progress toward your target return.')],
                    'drawdown' => [__('Drawdown'), __('The worst historical peak-to-trough loss.')],
                    'concentration' => [__('Concentration'), __('How large your single biggest position is.')],
                ] as $key => [$label, $description])
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">{{ $label }}
                            </flux:text>
                            <flux:text class="text-xs">{{ $description }}</flux:text>
                        </div>
                        <flux:badge size="sm" dir="ltr">{{ round(config('mahafeth.health_weights')[$key] * 100) }}%
                        </flux:badge>
                    </div>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
