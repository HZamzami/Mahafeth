<?php

use App\Services\Analytics\AlertEvaluator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Hide an alert until its underlying metric changes. Pruning to the
     * currently active fingerprints keeps the column from accumulating
     * stale entries as snapshots evolve.
     */
    public function dismiss(string $fingerprint): void
    {
        $user = Auth::user();
        $active = array_column($this->activeAlerts(), 'fingerprint');

        $dismissed = array_values(array_unique(array_intersect(
            [...($user->dismissed_alerts ?? []), $fingerprint],
            $active,
        )));

        $user->forceFill(['dismissed_alerts' => $dismissed])->save();
    }

    /**
     * Threshold alerts derived from the latest snapshot, minus the ones
     * the user dismissed.
     */
    public function with(): array
    {
        $dismissed = Auth::user()->dismissed_alerts ?? [];

        $visible = array_filter(
            $this->activeAlerts(),
            fn (array $alert): bool => ! in_array($alert['fingerprint'], $dismissed, true),
        );

        return [
            'alerts' => array_map(fn (array $alert): array => [
                'color' => $alert['color'],
                'fingerprint' => $alert['fingerprint'],
                'text' => __($alert['key'], $alert['params']),
            ], array_values($visible)),
        ];
    }

    /**
     * @return list<array{key: string, color: string, fingerprint: string, params: array<string, string>}>
     */
    private function activeAlerts(): array
    {
        $user = Auth::user();

        return app(AlertEvaluator::class)->evaluate(
            $user->latestSnapshot()?->metrics,
            $user->riskProfile,
        );
    }
}; ?>

<div class="{{ $alerts === [] ? 'hidden' : 'flex flex-col gap-2' }}">
    @foreach ($alerts as $alert)
        <flux:callout wire:key="alert-{{ $alert['fingerprint'] }}" wire:transition
            :color="$alert['color']" icon="exclamation-triangle" inline>
            <flux:callout.text>{{ $alert['text'] }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="xs" variant="ghost" :href="route('analytics')" wire:navigate>
                    {{ __('Details') }}</flux:button>
            </x-slot>
            <x-slot name="controls">
                <flux:button icon="x-mark" size="sm" variant="ghost" :aria-label="__('Dismiss')"
                    wire:click="dismiss('{{ $alert['fingerprint'] }}')" wire:loading.attr="disabled" />
            </x-slot>
        </flux:callout>
    @endforeach
</div>
