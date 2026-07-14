<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    protected $listeners = ['portfolio-analyzed' => '$refresh'];

    /**
     * Headline greeting plus total value and its move since the previous
     * snapshot, the first thing a phone user sees.
     */
    public function with(): array
    {
        $snapshots = Auth::user()->portfolioSnapshots()->orderByDesc('as_of')->limit(2)->get();
        $latest = $snapshots->first();
        $previous = $snapshots->skip(1)->first();

        $change = $latest !== null && $previous !== null && $previous->total_value > 0
            ? $latest->total_value - $previous->total_value
            : null;

        // The greeting follows local wall-clock time, not the UTC the app
        // runs on.
        $hour = now(config('mahafeth.display_timezone'))->hour;

        return [
            'greeting' => match (true) {
                $hour < 12 => __('Good morning, :name', ['name' => Str::before(Auth::user()->name, ' ')]),
                $hour < 17 => __('Good afternoon, :name', ['name' => Str::before(Auth::user()->name, ' ')]),
                default => __('Good evening, :name', ['name' => Str::before(Auth::user()->name, ' ')]),
            },
            'totalValue' => $latest?->total_value,
            'change' => $change,
            'changePercent' => $change !== null ? $change / $previous->total_value : null,
        ];
    }
}; ?>

<div class="card p-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:text class="text-sm">{{ $greeting }}</flux:text>

            @if ($totalValue !== null)
                <flux:text class="mt-3 text-xs font-medium uppercase tracking-widest">
                    {{ __('Total Portfolio') }}</flux:text>
                <a class="group mt-1 flex items-center gap-2" href="{{ route('holdings.index') }}" wire:navigate>
                    <span class="text-4xl font-semibold tabular-nums text-zinc-900 dark:text-white" dir="ltr"
                        data-amount>⃁ {{ Number::format($totalValue, 0) }}</span>
                    <flux:icon.chevron-right
                        class="size-5 text-zinc-400 transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
                </a>

                @if ($change !== null)
                    <p class="mt-1 flex items-center gap-1 text-sm font-medium {{ $change >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}"
                        data-amount>
                        @if ($change >= 0)
                            <flux:icon.arrow-trending-up class="size-4" />
                        @else
                            <flux:icon.arrow-trending-down class="size-4" />
                        @endif
                        <span dir="ltr">{{ $change >= 0 ? '+' : '−' }}⃁ {{ Number::format(abs($change), 0) }}
                            ({{ Number::percentage(abs($changePercent) * 100, 2) }})</span>
                        <span>{{ __('since last analysis') }}</span>
                    </p>
                @endif
            @else
                <flux:heading class="mt-3" size="lg">{{ __('Your unified portfolio starts here') }}</flux:heading>
                <flux:button class="mt-3" size="sm" variant="primary" :href="route('connections')" wire:navigate>
                    {{ __('Connect your accounts') }}</flux:button>
            @endif
        </div>

        @if ($totalValue !== null)
            <flux:button variant="subtle" size="sm" x-on:click="toggle()"
                x-bind:aria-pressed="hideAmounts.toString()" :aria-label="__('Hide balances')">
                <flux:icon.eye x-show="! hideAmounts" class="size-5" />
                <flux:icon.eye-slash x-show="hideAmounts" x-cloak class="size-5" />
            </flux:button>
        @endif
    </div>
</div>
