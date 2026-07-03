<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    private const CONCENTRATION_THRESHOLD = 0.30;

    private const VOLATILITY_OVERSHOOT = 1.33;

    private const STRESS_CORRELATION_THRESHOLD = 0.50;

    /**
     * Threshold alerts derived from the latest snapshot.
     */
    public function with(): array
    {
        $user = Auth::user();
        $metrics = $user->latestSnapshot()?->metrics;

        if ($metrics === null) {
            return ['alerts' => []];
        }

        $alerts = [];

        $largest = $metrics['largest_position'] ?? null;
        if ($largest !== null && $largest['weight'] > self::CONCENTRATION_THRESHOLD) {
            $alerts[] = [
                'color' => 'red',
                'text' => __('Concentration alert: :name is :weight of your portfolio — above the :threshold threshold.', [
                    'name' => $largest['name'],
                    'weight' => Number::percentage($largest['weight'] * 100, 1),
                    'threshold' => Number::percentage(self::CONCENTRATION_THRESHOLD * 100),
                ]),
            ];
        }

        $target = $user->riskProfile?->target_volatility;
        if ($target !== null && ($metrics['volatility'] ?? 0) > $target * self::VOLATILITY_OVERSHOOT) {
            $alerts[] = [
                'color' => 'amber',
                'text' => __('Risk alert: portfolio volatility of :volatility is well above your :target target.', [
                    'volatility' => Number::percentage($metrics['volatility'] * 100, 1),
                    'target' => Number::percentage($target * 100, 1),
                ]),
            ];
        }

        if (($metrics['stress_correlation'] ?? 0) > self::STRESS_CORRELATION_THRESHOLD) {
            $alerts[] = [
                'color' => 'amber',
                'text' => __('Correlation alert: in a market crisis your assets would move together with an estimated correlation of :correlation.', [
                    'correlation' => number_format($metrics['stress_correlation'], 2),
                ]),
            ];
        }

        return ['alerts' => $alerts];
    }
}; ?>

<div class="{{ $alerts === [] ? 'hidden' : 'flex flex-col gap-2' }}">
    @foreach ($alerts as $alert)
        <flux:callout :color="$alert['color']" icon="exclamation-triangle" inline>
            <flux:callout.text>{{ $alert['text'] }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="xs" variant="ghost" :href="route('analytics')" wire:navigate>
                    {{ __('Details') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endforeach
</div>
