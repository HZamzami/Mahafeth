{{-- The handful of valuation numbers a non-specialist actually compares:
     size, price-to-earnings, per-share profit, payout, and leverage. --}}
@props(['stats', 'currencySymbol'])

@php
use Illuminate\Support\Number;

$rows = [
    // Yahoo reports debt-to-equity as a percentage; readers know it as a ratio.
    [__('Market Cap'), $stats['marketCap'] !== null ? $currencySymbol.' '.Number::localizedAbbreviate($stats['marketCap']) : null],
    [__('P/E Ratio'), $stats['trailingPE'] !== null ? number_format($stats['trailingPE'], 1) : null],
    [__('EPS (trailing year)'), $stats['trailingEps'] !== null ? number_format($stats['trailingEps'], 2) : null],
    [__('Dividend Yield'), $stats['dividendYield'] !== null ? Number::percentage($stats['dividendYield'] * 100, 2) : null],
    [__('Debt to Equity'), $stats['debtToEquity'] !== null ? number_format($stats['debtToEquity'] / 100, 2) : null],
];
@endphp

<div class="card p-5">
    <div class="flex items-center justify-between">
        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
            {{ __('Key Stats') }}</flux:heading>
        <flux:text class="text-xs !text-neutral-400">{{ __('Data by Yahoo Finance') }}</flux:text>
    </div>

    <div class="mt-4 space-y-2.5">
        @foreach ($rows as [$label, $value])
            <div class="flex items-center justify-between gap-2">
                <flux:text class="text-sm">{{ $label }}</flux:text>
                <flux:text class="text-sm font-medium tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                    {{ $value ?? '—' }}</flux:text>
            </div>
        @endforeach
    </div>
</div>
