<?php

use App\Services\Markets\YahooMarketMovers;
use Livewire\Volt\Component;

/**
 * Today's US-market gainers, losers, and most active names. Loaded
 * lazily because the data comes from an external API on first view;
 * when it is unavailable the section renders empty.
 */
new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    public function with(): array
    {
        return [
            'movers' => app(YahooMarketMovers::class)->fetch(),
            'sections' => [
                'gainers' => __('Top Gainers'),
                'losers' => __('Top Losers'),
                'actives' => __('Most Active'),
            ],
        ];
    }
}; ?>

<div class="empty:hidden">
    @if ($movers !== null)
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                    {{ __('US Market Today') }}</flux:heading>
                <flux:text class="text-xs !text-neutral-400">{{ __('Data by Yahoo Finance') }}</flux:text>
            </div>

            {{-- One full-width list at a time: segmented tabs keep every
                 row aligned instead of squeezing three narrow columns. --}}
            <flux:tab.group class="mt-4">
                <flux:tabs variant="segmented" size="sm">
                    @foreach ($sections as $key => $label)
                        <flux:tab :name="$key" wire:key="movers-tab-{{ $key }}">{{ $label }}</flux:tab>
                    @endforeach
                </flux:tabs>

                @foreach ($sections as $key => $label)
                    <flux:tab.panel :name="$key" class="!pt-4" wire:key="movers-panel-{{ $key }}">
                        @if (($movers[$key] ?? []) !== [])
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($movers[$key] as $mover)
                                    <a class="flex items-center justify-between gap-3 py-2.5 transition-transform active:scale-[0.99]"
                                        href="{{ route('explore.instrument', $mover['symbol']) }}" wire:navigate
                                        wire:key="mover-{{ $key }}-{{ $mover['symbol'] }}">
                                        {{-- Ticker only: company names vary wildly in length and
                                             wreck the column rhythm; the detail page has the rest. --}}
                                        <span class="text-sm font-medium text-zinc-800 dark:text-white" dir="ltr">
                                            {{ $mover['symbol'] }}</span>
                                        <span class="shrink-0 text-end">
                                            @if ($mover['price'] !== null)
                                                <span class="block text-sm tabular-nums text-zinc-800 dark:text-white" dir="ltr">
                                                    {{ number_format($mover['price'], 2) }}</span>
                                            @endif
                                            @if ($mover['changePercent'] !== null)
                                                <span class="block text-xs font-medium tabular-nums {{ $mover['changePercent'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}"
                                                    dir="ltr">
                                                    {{ ($mover['changePercent'] >= 0 ? '+' : '−').number_format(abs($mover['changePercent']), 2) }}%</span>
                                            @endif
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <flux:text class="py-4 text-center text-sm">{{ __('Nothing to show right now.') }}</flux:text>
                        @endif
                    </flux:tab.panel>
                @endforeach
            </flux:tab.group>
        </div>
    @endif
</div>
