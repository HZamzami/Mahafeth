<?php

use App\Services\Analytics\AlertEvaluator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Threshold alerts derived from the latest snapshot.
     */
    public function with(): array
    {
        $user = Auth::user();

        $alerts = app(AlertEvaluator::class)->evaluate(
            $user->latestSnapshot()?->metrics,
            $user->riskProfile,
        );

        return [
            'alerts' => array_map(fn (array $alert): array => [
                'color' => $alert['color'],
                'text' => __($alert['key'], $alert['params']),
            ], $alerts),
        ];
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
