<?php

use App\Enums\ShariahStatus;
use App\Services\Analytics\HoldingsSummarizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return ['holdings' => app(HoldingsSummarizer::class)->rows(Auth::user())];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Holdings') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Every position across your connected accounts. Tap one for its market chart and details.') }}
        </flux:text>
    </div>

    @if ($holdings['rows'] !== [])
        <div class="card p-5">
            <div class="flex items-baseline justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-widest">{{ __('Total Portfolio') }}</flux:text>
                <flux:heading size="lg" dir="ltr">⃁ {{ Number::format($holdings['totalValue'], 0) }}</flux:heading>
            </div>
        </div>

        <div class="card divide-y divide-neutral-100 p-2 dark:divide-zinc-800">
            @foreach ($holdings['rows'] as $row)
                <a class="flex items-center gap-3 rounded-lg px-3 py-3 transition-colors hover:bg-neutral-50 active:scale-[0.99] dark:hover:bg-zinc-800/60"
                    href="{{ route('holdings.detail', $row['symbol']) }}" wire:navigate>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:text class="truncate text-sm font-medium !text-zinc-800 dark:!text-white">
                                {{ $row['name'] }}</flux:text>
                            @if ($row['shariah'] === ShariahStatus::NonCompliant)
                                <flux:badge color="red" size="sm">{{ $row['shariah']->label() }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="text-xs">
                            {{ $row['symbol'] }} &bull; {{ Number::percentage($row['weight'] * 100, 1) }}
                        </flux:text>
                        <div class="mt-1.5 h-1 w-full max-w-40 overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
                            <div class="bar-fill h-full bg-teal-600 dark:bg-teal-400" style="width: 0%"
                                data-width="{{ round($row['weight'] * 100) }}" x-data
                                x-intersect.once="$el.style.width = $el.dataset.width + '%'"></div>
                        </div>
                    </div>

                    <div class="shrink-0 text-end">
                        <flux:text class="text-sm font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                            ⃁ {{ Number::format($row['value'], 0) }}</flux:text>
                        <flux:text
                            class="text-xs tabular-nums {{ $row['pl'] >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            dir="ltr">
                            {{ $row['pl'] >= 0 ? '+' : '−' }}{{ number_format(abs($row['plPct']) * 100, 1) }}%
                        </flux:text>
                    </div>

                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400 rtl:rotate-180" />
                </a>
            @endforeach
        </div>
    @else
        <div class="flex flex-col items-center gap-4 card p-12 text-center">
            <flux:text class="text-sm">{{ __('No sources connected yet') }}</flux:text>
            <flux:button variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @endif
</div>
