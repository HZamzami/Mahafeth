<?php

use App\Services\Analytics\DailyMoveAttributor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    protected $listeners = ['portfolio-analyzed' => '$refresh'];

    /**
     * Attribution of the move between the two most recent snapshots.
     */
    public function with(): array
    {
        $snapshots = Auth::user()->portfolioSnapshots()
            ->orderByDesc('as_of')
            ->limit(2)
            ->get();

        $move = app(DailyMoveAttributor::class)->attribute($snapshots->first(), $snapshots->get(1));

        return [
            'move' => $move,
            'drivers' => $move === null ? [] : array_slice($move['contributions'], 0, 2),
        ];
    }
}; ?>

{{-- The root must always render for Livewire, but an empty div would still
     eat a flex gap in the dashboard column, so collapse it when hidden. --}}
<div @class(['hidden' => $move === null])>
    @if ($move !== null)
        @php($up = $move['total_change_pct'] >= 0)
        <div class="card p-5" x-data="{ open: false }">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">
                        {{ __('Daily Move') }}</flux:text>
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <flux:heading
                            class="{{ $up ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            size="xl" dir="ltr">
                            {{ ($up ? '+' : '') . Number::percentage($move['total_change_pct'] * 100, 2) }}
                        </flux:heading>
                        <flux:text class="text-xs">
                            {{ __('since :date', ['date' => \Illuminate\Support\Carbon::parse($move['previous_as_of'])->translatedFormat('j M')]) }}
                        </flux:text>
                    </div>
                    <flux:text class="mt-1 text-sm">
                        @foreach ($drivers as $driver)
                            <span class="whitespace-nowrap">{{ $driver['name'] }}
                                <span dir="ltr">{{ ($driver['pct'] >= 0 ? '+' : '') . Number::percentage($driver['pct'] * 100, 2) }}</span></span>@if (! $loop->last || $move['fx'] !== []) · @endif
                        @endforeach
                        @foreach ($move['fx'] as $fx)
                            <span class="whitespace-nowrap">{{ __(':currency FX', ['currency' => $fx['currency']]) }}
                                <span dir="ltr">{{ ($fx['pct'] >= 0 ? '+' : '') . Number::percentage($fx['pct'] * 100, 2) }}</span></span>@if (! $loop->last) · @endif
                        @endforeach
                    </flux:text>
                </div>
                @if (count($move['contributions']) > count($drivers))
                    <flux:button size="xs" variant="ghost" icon="chevron-down" x-on:click="open = ! open"
                        x-bind:class="open && 'rotate-180'" :aria-label="__('Show all contributions')" />
                @endif
            </div>

            <div class="mt-3 space-y-1" x-cloak x-show="open">
                @foreach ($move['contributions'] as $contribution)
                    <div class="flex items-center justify-between text-sm">
                        <flux:text>{{ $contribution['name'] }} ({{ $contribution['symbol'] }})</flux:text>
                        <span dir="ltr"
                            class="tabular-nums {{ $contribution['pct'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ ($contribution['pct'] >= 0 ? '+' : '') . Number::percentage($contribution['pct'] * 100, 2) }}
                        </span>
                    </div>
                @endforeach
                @if (abs($move['flows_pct']) >= 0.0001)
                    <div class="flex items-center justify-between border-t border-neutral-100 pt-1 text-sm dark:border-zinc-800">
                        <flux:text>{{ __('Deposits & trades') }}</flux:text>
                        <span dir="ltr" class="tabular-nums text-zinc-500">
                            {{ ($move['flows_pct'] >= 0 ? '+' : '') . Number::percentage($move['flows_pct'] * 100, 2) }}
                        </span>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
