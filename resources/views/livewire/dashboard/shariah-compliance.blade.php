<?php

use App\Enums\ObligationKind;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    protected $listeners = ['portfolio-analyzed' => '$refresh'];

    public ?string $purifiedAmount = null;

    public ?string $zakatPaidAmount = null;

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    /**
     * Record a purification settlement through today and refresh the
     * analysis so the outstanding amount reads zero immediately.
     */
    public function markPurified(PortfolioAnalyzer $analyzer): void
    {
        $this->validate(
            ['purifiedAmount' => ['required', 'numeric', 'min:0.01']],
            attributes: ['purifiedAmount' => __('amount')],
        );

        Auth::user()->obligationSettlements()->create([
            'kind' => ObligationKind::Purification,
            'amount' => (float) $this->purifiedAmount,
            'settled_through' => today()->toDateString(),
        ]);

        $analyzer->analyze(Auth::user());

        $this->modal('mark-purified')->close();
        $this->purifiedAmount = null;
        $this->dispatch('portfolio-analyzed');
    }

    /**
     * Record a zakat payment for the current hawl cycle.
     */
    public function markZakatPaid(): void
    {
        $this->validate(
            ['zakatPaidAmount' => ['required', 'numeric', 'min:0.01']],
            attributes: ['zakatPaidAmount' => __('amount')],
        );

        Auth::user()->obligationSettlements()->create([
            'kind' => ObligationKind::Zakat,
            'amount' => (float) $this->zakatPaidAmount,
            'settled_through' => today()->toDateString(),
        ]);

        $this->modal('mark-zakat-paid')->close();
        $this->zakatPaidAmount = null;
    }

    /**
     * The user's hawl cycle: next completion date and whether this cycle's
     * zakat is already recorded as paid.
     *
     * @return array{next: \Illuminate\Support\Carbon, paid: bool, paid_on: ?\Illuminate\Support\Carbon}|null
     */
    private function hawl(): ?array
    {
        $user = Auth::user();

        if ($user->zakat_hawl_month === null || $user->zakat_hawl_day === null) {
            return null;
        }

        $next = \App\Support\HijriDate::nextGregorian($user->zakat_hawl_month, $user->zakat_hawl_day);
        $previous = \App\Support\HijriDate::gregorian(
            \App\Support\HijriDate::toHijri($next)['year'] - 1,
            $user->zakat_hawl_month,
            $user->zakat_hawl_day,
        );

        $paidOn = $user->settledThrough(ObligationKind::Zakat);

        return [
            'next' => $next,
            'paid' => $paidOn !== null && $paidOn->gte($previous),
            'paid_on' => $paidOn,
        ];
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
            // Snapshots from before the settlement ledger only carry the
            // trailing-year amount; treat it as the outstanding balance.
            'outstanding' => $shariah === null ? 0.0 : ($shariah['purification_outstanding'] ?? $shariah['purification_amount'] ?? 0.0),
            'purifiedThrough' => $shariah['last_purified_through'] ?? null,
            'settlements' => Auth::user()->obligationSettlements()
                ->where('kind', ObligationKind::Purification)
                ->latest('settled_through')
                ->limit(5)
                ->get(),
            'hawl' => $this->hawl(),
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

        @if ($outstanding > 0 || $purifiedThrough !== null || $settlements->isNotEmpty())
            <div class="mt-4 rounded-lg bg-neutral-50 p-3 dark:bg-zinc-800/50">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-widest">
                        {{ __('Stock Purification') }}</flux:text>
                    @if ($outstanding > 0)
                        <flux:text class="text-sm font-semibold !text-red-600 dark:!text-red-400" dir="ltr" data-amount>
                            ⃁ {{ Number::format($outstanding, 2) }}</flux:text>
                    @else
                        <flux:badge color="emerald" size="sm">{{ __('Settled') }}</flux:badge>
                    @endif
                </div>

                @if ($outstanding > 0)
                    <flux:text class="mt-1 text-xs">
                        {{ __('Impure income received since your last purification, to be donated to charity.') }}
                    </flux:text>
                    <div class="mt-2 flex items-center gap-3">
                        <flux:modal.trigger name="mark-purified">
                            <flux:button size="xs" variant="primary"
                                x-on:click="$wire.purifiedAmount = '{{ number_format($outstanding, 2, '.', '') }}'">
                                {{ __('Mark as purified') }}</flux:button>
                        </flux:modal.trigger>
                        <a class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:underline dark:text-teal-300"
                            href="https://ehsan.sa/stockspurification" target="_blank" rel="noopener">
                            {{ __('Donate via Ehsan') }}
                            <flux:icon.arrow-top-right-on-square class="size-3" />
                        </a>
                    </div>
                @elseif ($purifiedThrough !== null)
                    <flux:text class="mt-1 text-xs">
                        {{ __('Purified through :date — nothing outstanding.', ['date' => \Illuminate\Support\Carbon::parse($purifiedThrough)->translatedFormat('j M Y')]) }}
                    </flux:text>
                @endif

                @if ($settlements->isNotEmpty())
                    <div class="mt-2" x-data="{ open: false }">
                        <button type="button" class="flex items-center gap-1 text-xs text-zinc-500 hover:underline"
                            x-on:click="open = ! open">
                            {{ __('Purification history') }}
                            <flux:icon.chevron-down class="size-3 transition-transform" x-bind:class="open && 'rotate-180'" />
                        </button>
                        <div class="mt-1 space-y-0.5" x-cloak x-show="open">
                            @foreach ($settlements as $settlement)
                                <div class="flex items-center justify-between text-xs text-zinc-500">
                                    <span>{{ $settlement->settled_through->translatedFormat('j M Y') }}</span>
                                    <span dir="ltr" data-amount>⃁ {{ Number::format($settlement->amount, 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <flux:modal name="mark-purified" class="md:w-96">
                <form wire:submit="markPurified" class="space-y-4 text-start">
                    <flux:heading size="lg">{{ __('Mark as purified') }}</flux:heading>
                    <flux:text class="text-sm">
                        {{ __('Record the amount you donated. Purification restarts from today: only new impure income will accrue.') }}
                    </flux:text>
                    <flux:input wire:model="purifiedAmount" type="number" step="0.01" min="0.01" dir="ltr"
                        :label="__('Amount donated (SAR)')" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            {{ __('Confirm') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
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

                @if ($hawl !== null)
                    @if ($hawl['paid'])
                        <flux:text class="mt-2 flex items-center gap-1.5 text-xs !text-emerald-600 dark:!text-emerald-400">
                            <flux:icon.check-circle class="size-3.5 shrink-0" />
                            {{ __('Zakat paid on :date for this hawl.', ['date' => $hawl['paid_on']->translatedFormat('j M Y')]) }}
                        </flux:text>
                    @else
                        <flux:text class="mt-2 text-xs">
                            {{ trans_choice('{0} Hawl completes today — :hijri.|{1} Hawl completes tomorrow — :hijri.|[2,*] Hawl completes in :count days — :hijri.', (int) today()->diffInDays($hawl['next']), ['hijri' => \App\Support\HijriDate::format($hawl['next'])]) }}
                        </flux:text>
                        @if (! $zakat['below_nisab'])
                            <flux:modal.trigger name="mark-zakat-paid">
                                <flux:button class="mt-2" size="xs" variant="outline"
                                    x-on:click="$wire.zakatPaidAmount = '{{ number_format($zakat['zakat_due'], 2, '.', '') }}'">
                                    {{ __('Mark zakat paid') }}</flux:button>
                            </flux:modal.trigger>
                        @endif
                    @endif
                @else
                    <a class="mt-2 inline-block text-xs font-medium text-teal-700 hover:underline dark:text-teal-300"
                        href="{{ route('settings.profile') }}" wire:navigate>
                        {{ __('Set your hawl date for reminders') }}</a>
                @endif
            </div>

            <flux:modal name="mark-zakat-paid" class="md:w-96">
                <form wire:submit="markZakatPaid" class="space-y-4 text-start">
                    <flux:heading size="lg">{{ __('Mark zakat paid') }}</flux:heading>
                    <flux:text class="text-sm">
                        {{ __('Record the zakat you paid for this hawl. The card will show it as settled until your next hawl completes.') }}
                    </flux:text>
                    <flux:input wire:model="zakatPaidAmount" type="number" step="0.01" min="0.01" dir="ltr"
                        :label="__('Amount paid (SAR)')" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            {{ __('Confirm') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</div>
