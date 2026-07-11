<?php

use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\StressScenarioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public string $scenario = 'oil_correction';

    /**
     * Replays the selected shock on the latest snapshot's real weights.
     */
    public function with(): array
    {
        $scenarios = config('mahafeth.stress_scenarios');

        if (! isset($scenarios[$this->scenario])) {
            $this->scenario = array_key_first($scenarios);
        }

        $user = Auth::user();
        $snapshot = $user->latestSnapshot();
        $weights = $snapshot?->metrics['weights'] ?? [];

        if ($snapshot === null || $weights === []) {
            return ['scenarios' => $scenarios, 'result' => null];
        }

        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $assets = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears))['assets'];

        $result = app(StressScenarioAnalyzer::class)->apply($weights, $assets, $scenarios[$this->scenario]);

        return [
            'scenarios' => $scenarios,
            'result' => $result,
            'totalValue' => (float) $snapshot->total_value,
            'shockedValue' => (float) $snapshot->total_value * (1 + $result['impact']),
        ];
    }
}; ?>

<div class="card p-5">
    <flux:heading size="lg">{{ __('Stress Test') }}</flux:heading>
    <flux:text class="mb-4 mt-1 text-sm">
        {{ __('What a named market shock would do to your actual portfolio, position by position.') }}
    </flux:text>

    @if ($result === null)
        <flux:text class="text-sm">{{ __('Run the analysis to stress test your portfolio.') }}</flux:text>
    @else
        <div class="sm:hidden">
            <flux:select wire:model.live="scenario">
                @foreach ($scenarios as $key => $definition)
                    <flux:select.option value="{{ $key }}">{{ __($definition['label']) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="hidden sm:block">
            <flux:radio.group variant="segmented" size="sm" wire:model.live="scenario">
                @foreach ($scenarios as $key => $definition)
                    <flux:radio value="{{ $key }}" :label="__($definition['label'])" />
                @endforeach
            </flux:radio.group>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-4">
            <div>
                <flux:text class="text-xs">{{ __('Portfolio value under this scenario') }}</flux:text>
                <flux:heading size="lg" dir="ltr">⃁ {{ Number::localizedAbbreviate($shockedValue, 1) }}</flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Estimated impact') }}</flux:text>
                <flux:heading class="!text-red-600 dark:!text-red-400" size="lg" dir="ltr">
                    {{ Number::percentage($result['impact'] * 100, 1) }}</flux:heading>
            </div>
        </div>

        <div class="mt-4 border-t border-neutral-200 pt-3 dark:border-neutral-700">
            <flux:text class="mb-2 text-xs font-medium uppercase tracking-widest">
                {{ __('Hardest-Hit Positions') }}</flux:text>
            <div class="space-y-2.5">
                @foreach ($result['positions'] as $position)
                    <div wire:key="stress-position-{{ $position['symbol'] }}">
                        <div class="flex items-center justify-between">
                            <flux:text class="text-sm">{{ $position['name'] }}
                                <span class="text-neutral-400">({{ $position['symbol'] }})</span></flux:text>
                            <span class="flex items-center gap-2">
                                <flux:text class="text-xs" dir="ltr">
                                    {{ __(':weight of portfolio', ['weight' => Number::percentage($position['weight'] * 100, 1)]) }}
                                </flux:text>
                                <flux:badge color="red" size="sm" dir="ltr">
                                    {{ Number::percentage($position['shock'] * 100, 0) }}</flux:badge>
                            </span>
                        </div>
                        {{-- Slides to its new level when the scenario changes. --}}
                        <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
                            <div class="bar-fill h-full bg-red-500 dark:bg-red-400"
                                style="width: {{ min(100, round(abs($position['shock']) * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
