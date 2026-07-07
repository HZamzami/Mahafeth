<?php

use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Run the first analysis directly from the checklist.
     */
    public function analyze(PortfolioAnalyzer $analyzer): void
    {
        $analyzer->analyze(Auth::user());

        $this->dispatch('portfolio-analyzed');
    }

    /**
     * Step states for the first-run journey; the card disappears once a
     * snapshot exists.
     */
    public function with(): array
    {
        $user = Auth::user();

        $connected = $user->connections()->exists();
        $profiled = $user->riskProfile !== null;
        $analyzed = $user->latestSnapshot() !== null;

        $steps = [
            [
                'label' => __('Connect your accounts'),
                'description' => __('Link your platforms through Open Banking to build one unified portfolio.'),
                'done' => $connected,
                'current' => ! $connected,
                'href' => route('connections'),
                'action' => __('Connect accounts'),
            ],
            [
                'label' => __('Build your investor profile'),
                'description' => __('Six quick questions define the goals and risk tolerance your portfolio is judged against.'),
                'done' => $profiled,
                'current' => $connected && ! $profiled,
                'href' => route('investor-profile'),
                'action' => __('Start'),
            ],
            [
                'label' => __('Run your first analysis'),
                'description' => __('Mahafeth scores your portfolio and explains what to do about it.'),
                'done' => $analyzed,
                'current' => $connected && $profiled && ! $analyzed,
                'href' => null,
                'action' => __('Analyze now'),
            ],
        ];

        return ['steps' => $steps, 'analyzed' => $analyzed, 'connected' => $connected];
    }
}; ?>

{{-- Collapse the Livewire root once onboarding is done so it does not
     eat a flex gap in the dashboard column. --}}
<div @class(['hidden' => $analyzed])>
    @if (! $analyzed)
        <div class="card p-6">
            <flux:heading size="lg">{{ __('Welcome to Mahafeth') }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Three steps from scattered portfolios to one investment vision.') }}</flux:text>

            <div class="mt-5 grid gap-4 lg:grid-cols-3">
                @foreach ($steps as $index => $step)
                    <div @class([
                        'rounded-lg border p-4',
                        'border-teal-600/40 bg-teal-50/50 dark:border-teal-400/30 dark:bg-teal-500/5' => $step['current'],
                        'border-neutral-200/60 bg-neutral-50 dark:border-neutral-700/60 dark:bg-zinc-800/50' => ! $step['current'],
                    ])>
                        <div class="flex items-center gap-2">
                            @if ($step['done'])
                                <flux:icon.check-circle class="size-5 shrink-0 text-emerald-500" variant="solid" />
                            @else
                                <span @class([
                                    'flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                    'bg-teal-600 text-white dark:bg-teal-400 dark:text-teal-950' => $step['current'],
                                    'bg-neutral-200 text-neutral-500 dark:bg-zinc-700 dark:text-zinc-400' => ! $step['current'],
                                ])>{{ $index + 1 }}</span>
                            @endif
                            <flux:heading size="sm">{{ $step['label'] }}</flux:heading>
                        </div>

                        <flux:text class="mt-2 text-xs">{{ $step['description'] }}</flux:text>

                        @if ($step['current'])
                            <div class="mt-3">
                                @if ($step['href'] !== null)
                                    <flux:button size="sm" variant="primary" :href="$step['href']" wire:navigate>
                                        {{ $step['action'] }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="primary" icon="sparkles" wire:click="analyze"
                                        wire:loading.attr="disabled">{{ $step['action'] }}</flux:button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
