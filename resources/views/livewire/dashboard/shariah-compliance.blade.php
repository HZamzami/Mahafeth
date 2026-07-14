<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    /**
     * Shariah screening results from the latest portfolio snapshot.
     */
    public function with(): array
    {
        $metrics = Auth::user()->latestSnapshot()?->metrics;
        $shariah = $metrics['shariah'] ?? null;

        return [
            'shariah' => $shariah,
            'zakat' => $metrics['zakat'] ?? null,
            'compliantPct' => $shariah !== null ? $shariah['compliant_weight'] * 100 : null,
            'nonCompliantPct' => $shariah !== null ? $shariah['non_compliant_weight'] * 100 : null,
            'unknownPct' => $shariah !== null ? $shariah['unknown_weight'] * 100 : null,
        ];
    }
}; ?>

<div
    class="card p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('Shariah Compliance') }}</flux:heading>
        <flux:icon.check-badge class="size-5 text-emerald-500" />
    </div>

    @if ($shariah === null)
        <flux:text class="mt-4 text-sm">
            {{ __('Connect your accounts and run the analysis to screen your portfolio.') }}</flux:text>
    @else
        <div class="mt-4">
            <flux:heading class="!text-emerald-600 dark:!text-emerald-400" size="xl" dir="ltr">
                {{ Number::percentage($compliantPct, 1) }}</flux:heading>
            <flux:text class="text-xs">{{ __('of portfolio value in Shariah-compliant assets') }}</flux:text>
        </div>

        <div class="mt-4 flex h-2 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800" x-data
            x-intersect.once="[...$el.children].forEach((bar) => bar.style.width = bar.dataset.width + '%')">
            <div class="bar-fill h-full bg-emerald-500 dark:bg-emerald-400" style="width: 0%"
                data-width="{{ round($compliantPct) }}"></div>
            <div class="bar-fill h-full bg-amber-400 dark:bg-amber-500" style="width: 0%"
                data-width="{{ round($unknownPct) }}"></div>
            <div class="bar-fill h-full bg-red-500 dark:bg-red-400" style="width: 0%"
                data-width="{{ round($nonCompliantPct) }}"></div>
        </div>

        <div class="mt-3 space-y-1">
            <div class="flex items-center justify-between text-xs">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-emerald-500"></span>
                    <flux:text class="text-xs">{{ __('Shariah Compliant') }}</flux:text>
                </span>
                <flux:text class="text-xs" dir="ltr">{{ Number::percentage($compliantPct, 1) }}</flux:text>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-amber-400"></span>
                    <flux:text class="text-xs">{{ __('Compliance Unknown') }}</flux:text>
                </span>
                <flux:text class="text-xs" dir="ltr">{{ Number::percentage($unknownPct, 1) }}</flux:text>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-red-500"></span>
                    <flux:text class="text-xs">{{ __('Not Shariah Compliant') }}</flux:text>
                </span>
                <flux:text class="text-xs" dir="ltr">{{ Number::percentage($nonCompliantPct, 1) }}</flux:text>
            </div>
        </div>

        @if ($shariah['non_compliant_positions'] !== [])
            <div class="mt-4 border-t border-neutral-200 pt-3 dark:border-neutral-700">
                <flux:text class="mb-2 text-xs font-medium uppercase tracking-widest">
                    {{ __('Flagged Positions') }}</flux:text>
                <div class="space-y-1.5">
                    @foreach ($shariah['non_compliant_positions'] as $position)
                        <div class="flex items-center justify-between">
                            <flux:text class="text-sm">{{ $position['name'] }}
                                <span class="text-neutral-400">({{ $position['symbol'] }})</span></flux:text>
                            <flux:badge color="red" size="sm" dir="ltr">
                                {{ Number::percentage($position['weight'] * 100, 1) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (($shariah['purification_amount'] ?? 0) > 0)
            <div class="mt-4 rounded-lg bg-neutral-50 p-3 dark:bg-zinc-800/50">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-widest">
                        {{ __('Stock Purification') }}</flux:text>
                    <flux:text class="text-sm font-semibold !text-red-600 dark:!text-red-400" dir="ltr" data-amount>
                        ⃁ {{ Number::format($shariah['purification_amount'], 2) }}</flux:text>
                </div>
                <flux:text class="mt-1 text-xs">
                    {{ __('Dividends received from non-compliant holdings over the past year, to be donated to charity.') }}
                </flux:text>
                <a class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:underline dark:text-teal-300"
                    href="https://ehsan.sa/stockspurification" target="_blank" rel="noopener">
                    {{ __('Donate via Ehsan') }}
                    <flux:icon.arrow-top-right-on-square class="size-3" />
                </a>
            </div>
        @endif

        @if ($zakat !== null)
            <div class="mt-4 rounded-lg bg-neutral-50 p-3 dark:bg-zinc-800/50">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-widest">
                        {{ __('Zakat Due') }}</flux:text>
                    <flux:text class="text-sm font-semibold !text-teal-700 dark:!text-teal-300" dir="ltr" data-amount>
                        @if ($zakat['below_nisab'])
                            {{ __('Below nisab') }}
                        @else
                            ⃁ {{ Number::format($zakat['zakat_due'], 2) }}
                        @endif
                    </flux:text>
                </div>
                <flux:text class="mt-1 text-xs">
                    {{ __('2.5% of your zakatable wealth (cash, equities, funds, and crypto at market value).') }}
                </flux:text>
            </div>
        @endif
    @endif
</div>
