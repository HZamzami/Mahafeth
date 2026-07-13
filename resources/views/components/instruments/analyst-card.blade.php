{{-- CNN-Markets-style analyst consensus: a segmented buy/hold/sell bar with
     counts, then the 12-month price-target range with the average target and
     today's price marked on it. Replaces the old TradingView technical
     gauge, which scored crowd-voted indicators rather than analyst research. --}}
@props(['ratings', 'priceTarget', 'currencySymbol'])

@php
$consensusLabel = match ($ratings['consensus'] ?? null) {
    'buy' => __('Buy'),
    'hold' => __('Hold'),
    'sell' => __('Sell'),
    default => null,
};

$consensusColor = match ($ratings['consensus'] ?? null) {
    'buy' => '!text-emerald-600 dark:!text-emerald-400',
    'sell' => '!text-red-600 dark:!text-red-400',
    default => '!text-amber-600 dark:!text-amber-400',
};

$segments = $ratings !== null ? [
    ['label' => __('Buy'), 'count' => $ratings['buy'], 'color' => 'bg-emerald-500'],
    ['label' => __('Hold'), 'count' => $ratings['hold'], 'color' => 'bg-amber-400'],
    ['label' => __('Sell'), 'count' => $ratings['sell'], 'color' => 'bg-red-500'],
] : [];

$upside = $priceTarget !== null && ($priceTarget['current'] ?? 0) > 0
    ? $priceTarget['mean'] / $priceTarget['current'] - 1
    : null;

// The range bar spans low..high, widened when today's price sits outside it.
$rangeMin = $priceTarget !== null ? min($priceTarget['low'], $priceTarget['current'] ?? $priceTarget['low']) : 0;
$rangeMax = $priceTarget !== null ? max($priceTarget['high'], $priceTarget['current'] ?? $priceTarget['high']) : 0;
$position = fn (float $value): float => $rangeMax > $rangeMin
    ? round(($value - $rangeMin) / ($rangeMax - $rangeMin) * 100)
    : 50.0;
@endphp

<div class="card p-5">
    <div class="flex items-center justify-between">
        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
            {{ __('Analyst Ratings') }}</flux:heading>
        <flux:text class="text-xs !text-neutral-400">{{ __('Data by Yahoo Finance') }}</flux:text>
    </div>

    @if ($ratings !== null)
        <div class="mt-4">
            @if ($consensusLabel !== null)
                <flux:heading class="{{ $consensusColor }}" size="lg">{{ $consensusLabel }}</flux:heading>
            @endif
            <flux:text class="mt-0.5 text-xs">
                {{ __('Based on :count analyst ratings.', ['count' => $ratings['total']]) }}</flux:text>

            <div class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800" dir="ltr">
                @foreach ($segments as $segment)
                    @if ($segment['count'] > 0)
                        <div class="{{ $segment['color'] }} h-full" style="width: {{ round($segment['count'] / $ratings['total'] * 100, 1) }}%"></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-3 space-y-1.5">
                @foreach ($segments as $segment)
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2">
                            <span class="{{ $segment['color'] }} size-2.5 rounded-full"></span>
                            <flux:text class="text-sm">{{ $segment['label'] }}</flux:text>
                        </span>
                        <flux:text class="text-sm font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                            {{ $segment['count'] }} ({{ round($segment['count'] / $ratings['total'] * 100) }}%)</flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($priceTarget !== null)
        <div class="{{ $ratings !== null ? 'mt-5 border-t border-neutral-100 pt-4 dark:border-zinc-800' : 'mt-4' }}">
            <flux:text class="text-xs">{{ __('Analyst Price Target (12 months)') }}</flux:text>
            <div class="mt-1 flex flex-wrap items-baseline gap-2">
                <flux:heading dir="ltr" size="lg">{{ $currencySymbol }} {{ number_format($priceTarget['mean'], 2) }}</flux:heading>
                @if ($upside !== null)
                    <span class="text-xs font-medium {{ $upside >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}" dir="ltr">
                        {{ ($upside >= 0 ? '+' : '−').number_format(abs($upside) * 100, 1) }}%
                        <span class="font-normal text-neutral-400">{{ $upside >= 0 ? __('above today’s price') : __('below today’s price') }}</span>
                    </span>
                @endif
            </div>

            <div class="relative mt-4 h-1.5 w-full rounded-full bg-neutral-100 dark:bg-zinc-800" dir="ltr">
                <div class="absolute inset-y-0 rounded-full bg-teal-600/30 dark:bg-teal-400/30"
                    style="left: {{ $position($priceTarget['low']) }}%; width: {{ max(0, $position($priceTarget['high']) - $position($priceTarget['low'])) }}%"></div>
                <span class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-teal-600 dark:bg-teal-400"
                    style="left: {{ $position($priceTarget['mean']) }}%" title="{{ __('Average target') }}"></span>
                @if ($priceTarget['current'] !== null)
                    <span class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-zinc-800 dark:border-zinc-900 dark:bg-white"
                        style="left: {{ $position($priceTarget['current']) }}%" title="{{ __('Current price') }}"></span>
                @endif
            </div>
            <div class="mt-1.5 flex justify-between" dir="ltr">
                <flux:text class="text-xs tabular-nums">{{ __('Low') }} {{ number_format($priceTarget['low'], 2) }}</flux:text>
                <flux:text class="text-xs tabular-nums">{{ __('High') }} {{ number_format($priceTarget['high'], 2) }}</flux:text>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-teal-600 dark:bg-teal-400"></span>
                    <flux:text class="text-xs">{{ __('Average target') }}</flux:text>
                </span>
                @if ($priceTarget['current'] !== null)
                    <span class="flex items-center gap-1.5">
                        <span class="size-2 rounded-full border border-zinc-400 bg-zinc-800 dark:bg-white"></span>
                        <flux:text class="text-xs">{{ __('Current price') }}</flux:text>
                    </span>
                @endif
            </div>
        </div>
    @endif

    <flux:text class="mt-4 text-center text-xs">
        {{ __('Analyst views are opinions, not investment advice.') }}</flux:text>
</div>
