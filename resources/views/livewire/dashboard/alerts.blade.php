<?php

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
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
        $active = [
            ...array_column($this->activeAlerts(), 'fingerprint'),
            ...array_column($this->resolvedCelebrations(), 'fingerprint'),
        ];

        $dismissed = array_values(array_unique(array_intersect(
            [...($user->dismissed_alerts ?? []), $fingerprint],
            $active,
        )));

        $user->forceFill(['dismissed_alerts' => $dismissed])->save();
    }

    /**
     * Threshold alerts derived from the latest snapshot, minus the ones
     * the user dismissed, plus celebrations for recently resolved ones.
     */
    public function with(): array
    {
        $dismissed = Auth::user()->dismissed_alerts ?? [];

        $visible = array_filter(
            [...$this->resolvedCelebrations(), ...$this->activeAlerts()],
            fn (array $alert): bool => ! in_array($alert['fingerprint'], $dismissed, true),
        );

        return [
            'alerts' => array_map(fn (array $alert): array => [
                'color' => $alert['color'],
                'fingerprint' => $alert['fingerprint'],
                'text' => __($alert['key'], $alert['params']),
                'resolved' => str_starts_with($alert['fingerprint'], 'resolved:'),
            ], array_values($visible)),
        ];
    }

    /**
     * @return list<array{key: string, color: string, fingerprint: string, params: array<string, string>}>
     */
    private function activeAlerts(): array
    {
        $user = Auth::user();

        return app(AlertEvaluator::class)->forUser($user, $user->latestSnapshot());
    }

    /**
     * Alerts that cleared in the past week, shown as positive follow-through
     * moments. The synthetic fingerprint keys dismissals off the event id.
     *
     * @return list<array{key: string, color: string, fingerprint: string, params: array<string, string>}>
     */
    private function resolvedCelebrations(): array
    {
        return ActivityEvent::whereBelongsTo(Auth::user())
            ->where('type', ActivityType::AlertResolved)
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->get()
            ->map(fn (ActivityEvent $event): array => [
                'key' => 'Nice work — resolved: :alert',
                'color' => 'emerald',
                'fingerprint' => 'resolved:'.$event->id,
                'params' => ['alert' => __($event->params['key'] ?? '', $event->params['params'] ?? [])],
            ])
            ->all();
    }
}; ?>

<div class="{{ $alerts === [] ? 'hidden' : 'flex flex-col gap-2' }}">
    @foreach ($alerts as $alert)
        <flux:callout wire:key="alert-{{ $alert['fingerprint'] }}" wire:transition
            :color="$alert['color']" :icon="$alert['resolved'] ? 'check-circle' : 'exclamation-triangle'" inline>
            <flux:callout.text>{{ $alert['text'] }}</flux:callout.text>
            @unless ($alert['resolved'])
                <x-slot name="actions">
                    <flux:button size="xs" variant="ghost" :href="route('analytics')" wire:navigate>
                        {{ __('Details') }}</flux:button>
                </x-slot>
            @endunless
            <x-slot name="controls">
                <flux:button icon="x-mark" size="sm" variant="ghost" :aria-label="__('Dismiss')"
                    wire:click="dismiss('{{ $alert['fingerprint'] }}')" wire:loading.attr="disabled" />
            </x-slot>
        </flux:callout>
    @endforeach
</div>
