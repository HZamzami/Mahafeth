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

        // 'Other' holds assets GICS cannot classify (cash, crypto, index
        // funds) and is not a sector, so it never wins this card.
        $sectors = array_diff_key($metrics['allocations']['sector'] ?? [], ['Other' => null]);

        return [
            'expectedReturn' => $metrics['expected_return'] ?? null,
            'windowYears' => $metrics['window_years'] ?? (int) config('mahafeth.analysis_window_years'),
            'volatility' => $metrics['volatility'] ?? null,
            // The bar fills completely at twice the investor's target volatility.
            'volatilityScale' => 2 * (Auth::user()->riskProfile?->target_volatility ?? 0.25),
            'largestPosition' => $metrics['largest_position'] ?? null,
            'largestSector' => array_key_first($sectors),
            'largestSectorWeight' => $sectors === [] ? 0.0 : reset($sectors),
        ];
    }
}; ?>

<div class="grid auto-rows-fr grid-cols-2 gap-4 md:grid-cols-4">
    <div
        class="flex flex-col justify-between card p-5">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Annualized Return') }}
            </flux:text>
            <flux:heading
                class="{{ ($expectedReturn ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                size="xl" dir="ltr">
                {{ $expectedReturn !== null ? Number::percentage($expectedReturn * 100, 1) : '—' }}</flux:heading>
        </div>
        <flux:text
            class="mt-2 flex items-center gap-1 text-xs {{ ($expectedReturn ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}">
            @if (($expectedReturn ?? 0) >= 0)
                <flux:icon.arrow-trending-up class="size-4" />
            @else
                <flux:icon.arrow-trending-down class="size-4" />
            @endif
            {{ __('Trailing :years-year window', ['years' => $windowYears]) }}
        </flux:text>
    </div>

    <div
        class="flex flex-col justify-between card p-5">
        <div>
            <flux:text class="mb-1 flex items-center gap-1 text-xs font-medium uppercase tracking-widest">
                {{ __('Annualized Volatility') }}
                <flux:tooltip toggleable :content="__('Bar fills at twice your target volatility')">
                    <flux:button size="xs" variant="ghost" icon="information-circle"
                        :aria-label="__('How is this calculated?')" />
                </flux:tooltip>
            </flux:text>
            <flux:heading class="!text-amber-600 dark:!text-amber-400" size="xl" dir="ltr">
                {{ $volatility !== null ? Number::percentage($volatility * 100, 1) : '—' }}</flux:heading>
        </div>
        <div class="mt-4 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
            <div class="bar-fill h-full bg-amber-500 dark:bg-amber-400" style="width: 0%"
                data-width="{{ min(100, round(($volatility ?? 0) / $volatilityScale * 100)) }}" x-data
                x-intersect.once="$el.style.width = $el.dataset.width + '%'"></div>
        </div>
    </div>

    <div
        class="flex flex-col justify-between card p-5">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Holding') }}</flux:text>
            <flux:heading size="xl">
                {{ $largestPosition !== null ? $largestPosition['name'].' ('.$largestPosition['symbol'].')' : '—' }}
            </flux:heading>
        </div>
        @if ($largestPosition !== null)
            <flux:text class="mt-2 text-xs">
                {{ __(':percent of Portfolio', ['percent' => Number::percentage($largestPosition['weight'] * 100, 1)]) }}
            </flux:text>
        @endif
    </div>

    <div
        class="flex flex-col justify-between card p-5">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Sector') }}</flux:text>
            <flux:heading class="leading-tight" size="xl">{{ $largestSector !== null ? __($largestSector) : '—' }}
            </flux:heading>
        </div>
        <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
            <div class="bar-fill h-full bg-teal-600 dark:bg-teal-400" style="width: 0%"
                data-width="{{ round($largestSectorWeight * 100) }}" x-data
                x-intersect.once="$el.style.width = $el.dataset.width + '%'"></div>
        </div>
    </div>
</div>
