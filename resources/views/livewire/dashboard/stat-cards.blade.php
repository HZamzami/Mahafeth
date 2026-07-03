<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Headline metrics from the latest portfolio snapshot.
     */
    public function with(): array
    {
        $metrics = Auth::user()->latestSnapshot()?->metrics;

        $sectors = $metrics['allocations']['sector'] ?? [];

        return [
            'expectedReturn' => $metrics['expected_return'] ?? null,
            'volatility' => $metrics['volatility'] ?? null,
            'largestPosition' => $metrics['largest_position'] ?? null,
            'largestSector' => array_key_first($sectors),
            'largestSectorWeight' => $sectors === [] ? 0.0 : reset($sectors),
        ];
    }
}; ?>

<div class="grid auto-rows-fr gap-4 md:grid-cols-4">
    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Expected Annualized Return') }}
            </flux:text>
            <flux:heading
                class="{{ ($expectedReturn ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                size="xl" dir="ltr">
                {{ $expectedReturn !== null ? Number::percentage($expectedReturn * 100, 1) : '—' }}</flux:heading>
        </div>
        <flux:text
            class="mt-2 flex items-center gap-1 text-xs {{ ($expectedReturn ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}">
            @if (($expectedReturn ?? 0) >= 0)
                <flux:icon.arrow-trending-up class="size-4" /> {{ __('Trailing 12 months') }}
            @else
                <flux:icon.arrow-trending-down class="size-4" /> {{ __('Trailing 12 months') }}
            @endif
        </flux:text>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Annualized Volatility') }}
            </flux:text>
            <flux:heading class="!text-amber-600 dark:!text-amber-400" size="xl" dir="ltr">
                {{ $volatility !== null ? Number::percentage($volatility * 100, 1) : '—' }}</flux:heading>
        </div>
        <div class="mt-4 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
            <div class="h-full bg-amber-500 dark:bg-amber-400"
                style="width: {{ min(100, round(($volatility ?? 0) * 200)) }}%"></div>
        </div>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Holding') }}</flux:text>
            <flux:heading size="xl">
                {{ $largestPosition !== null ? $largestPosition['name'].' ('.$largestPosition['symbol'].')' : '—' }}
            </flux:heading>
        </div>
        <flux:text class="mt-2 text-xs">
            {{ __(':percent of Portfolio', ['percent' => Number::percentage(($largestPosition['weight'] ?? 0) * 100, 1)]) }}
        </flux:text>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Sector') }}</flux:text>
            <flux:heading class="leading-tight" size="xl">{{ $largestSector !== null ? __($largestSector) : '—' }}
            </flux:heading>
        </div>
        <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
            <div class="h-full bg-blue-500 dark:bg-blue-400" style="width: {{ round($largestSectorWeight * 100) }}%">
            </div>
        </div>
    </div>
</div>
