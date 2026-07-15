<?php

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\CompanyFiling;
use App\Models\Holding;
use App\Models\NewsItem;
use App\Models\Transaction;
use App\Services\Analytics\RealizedGainCalculator;
use App\Services\Fx\FxRateService;
use App\Services\Markets\TradingViewSymbol;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public Asset $asset;

    /**
     * Prices default to the asset's native currency, the way brokers and
     * market apps quote them; the toggle converts to the base currency.
     */
    public bool $showBaseCurrency = false;

    public function mount(Asset $asset): void
    {
        abort_unless($this->userHoldings($asset)->exists(), 404);

        $this->asset = $asset;
        $this->showBaseCurrency = (bool) session('holdings.show_base_currency', false);
    }

    public function setCurrency(bool $base): void
    {
        $this->showBaseCurrency = $base;
        session(['holdings.show_base_currency' => $base]);
    }

    public function with(): array
    {
        $holdings = $this->userHoldings($this->asset)->get();
        $rate = $this->showBaseCurrency
            ? (app(FxRateService::class)->all()[$this->asset->currency] ?? 1.0)
            : 1.0;

        $quantity = $holdings->sum(fn (Holding $holding): float => (float) $holding->quantity);
        $cost = $holdings->sum(fn (Holding $holding): float => $holding->quantity * $holding->avg_cost * $rate);

        $stats = $this->priceStats($rate);
        $price = $stats['price'];
        $value = $price !== null ? $quantity * $price : null;

        $metrics = Auth::user()->latestSnapshot()?->metrics;

        return [
            ...$stats,
            'quantity' => $quantity,
            'avgCost' => $quantity > 0 ? $cost / $quantity : null,
            'value' => $value,
            'pl' => $value !== null ? $value - $cost : null,
            'plPct' => $value !== null && $cost > 0 ? ($value - $cost) / $cost : null,
            'realized' => app(RealizedGainCalculator::class)->forAsset(Auth::user(), $this->asset) * $rate,
            'weight' => $metrics['weights'][$this->asset->symbol] ?? null,
            'sectorExposure' => $this->asset->sector !== null
                ? ($metrics['allocations']['sector'][$this->asset->sector] ?? null)
                : null,
            'transactions' => Transaction::whereBelongsTo($this->asset)
                ->whereHas('account.connection', fn ($query) => $query
                    ->whereBelongsTo(Auth::user())
                    ->where('status', ConnectionStatus::Connected))
                ->latest('executed_at')
                ->limit(6)
                ->get(),
            'tradingViewSymbol' => TradingViewSymbol::for($this->asset->symbol, $this->asset->asset_class),
            'showChart' => $this->asset->asset_class !== AssetClass::Cash,
            'showFundamentals' => $this->asset->asset_class === AssetClass::Equity,
            'filings' => CompanyFiling::where('symbol', $this->asset->symbol)->latest('published_at')->limit(3)->get(),
            'news' => NewsItem::latest('published_at')->get()
                ->filter(fn (NewsItem $item): bool => in_array($this->asset->symbol, $item->symbols ?? [], true))
                ->take(3),
            'askPrompt' => __('Tell me about my :symbol position: how it affects my portfolio risk, and whether I should trim, hold, or add.', [
                'symbol' => $this->asset->symbol,
            ]),
            'currencySymbol' => $this->showBaseCurrency ? '⃁' : $this->asset->currencySymbol(),
            'canToggleCurrency' => $this->asset->currency !== config('mahafeth.base_currency'),
        ];
    }

    /**
     * Day change, trailing returns, and the 52-week range, all computed
     * from our own stored closes (converted to base currency).
     *
     * @return array{price: ?float, dayChange: ?float, dayChangePct: ?float, returns: array<string, ?float>, week52High: ?float, week52Low: ?float, week52Position: ?float}
     */
    private function priceStats(float $rate): array
    {
        $closes = $this->asset->priceHistories()
            ->where('date', '>=', now()->subDays(370))
            ->orderBy('date')
            ->pluck('close')
            ->map(fn (float $close): float => $close * $rate)
            ->values();

        if ($closes->count() < 2) {
            return [
                'price' => $closes->last(),
                'dayChange' => null,
                'dayChangePct' => null,
                'returns' => ['1W' => null, '1M' => null, '3M' => null, '1Y' => null],
                'week52High' => null,
                'week52Low' => null,
                'week52Position' => null,
            ];
        }

        $price = $closes->last();
        $previous = $closes->get($closes->count() - 2);

        // Trading-day offsets for the trailing-return windows.
        $returnFor = function (int $tradingDays) use ($closes, $price): ?float {
            $base = $closes->get(max(0, $closes->count() - 1 - $tradingDays));

            return $base > 0 ? $price / $base - 1 : null;
        };

        $high = $closes->max();
        $low = $closes->min();

        return [
            'price' => $price,
            'dayChange' => $price - $previous,
            'dayChangePct' => $previous > 0 ? $price / $previous - 1 : null,
            'returns' => [
                '1W' => $returnFor(5),
                '1M' => $returnFor(21),
                '3M' => $returnFor(63),
                '1Y' => $returnFor(252),
            ],
            'week52High' => $high,
            'week52Low' => $low,
            'week52Position' => $high > $low ? ($price - $low) / ($high - $low) : null,
        ];
    }

    /**
     * Only holdings that belong to the signed-in user's live connections.
     */
    private function userHoldings(Asset $asset)
    {
        return Holding::whereBelongsTo($asset)
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo(Auth::user())
                ->where('status', ConnectionStatus::Connected));
    }
}; ?>

<div class="stagger-children mx-auto flex w-full max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div>
        <flux:button size="sm" variant="ghost" :href="route('holdings.index')" wire:navigate>
            <flux:icon.chevron-left class="size-4 rtl:rotate-180" /> {{ __('Holdings') }}</flux:button>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl">{{ $asset->localizedName() }}</flux:heading>
                    <flux:text class="text-sm">{{ $asset->symbol }}</flux:text>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <flux:badge size="sm">{{ $asset->asset_class->label() }}</flux:badge>
                    @if ($asset->sector)
                        <flux:badge size="sm">{{ __($asset->sector) }}</flux:badge>
                    @endif
                    @if ($asset->shariah_status === ShariahStatus::Compliant)
                        <flux:badge color="emerald" size="sm">{{ $asset->shariah_status->label() }}</flux:badge>
                    @elseif ($asset->shariah_status === ShariahStatus::NonCompliant)
                        <flux:badge color="red" size="sm">{{ $asset->shariah_status->label() }}</flux:badge>
                    @else
                        <flux:tooltip :content="__('Not in our curated Islamic catalog yet — screen it with your own reference before relying on it.')">
                            <flux:badge color="zinc" size="sm" icon="question-mark-circle">{{ __('Compliance not screened') }}</flux:badge>
                        </flux:tooltip>
                    @endif
                </div>
            </div>

            @if ($price !== null)
                <div class="text-end">
                    <p class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white" dir="ltr">
                        {{ $currencySymbol }} {{ number_format($price, 2) }}</p>
                    @if ($dayChangePct !== null)
                        <p class="mt-0.5 flex items-center justify-end gap-1 text-sm font-medium {{ $dayChange >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            @if ($dayChange >= 0)
                                <flux:icon.arrow-trending-up class="size-4" />
                            @else
                                <flux:icon.arrow-trending-down class="size-4" />
                            @endif
                            <span dir="ltr">{{ $dayChange >= 0 ? '+' : '−' }}{{ number_format(abs($dayChange), 2) }}
                                ({{ number_format(abs($dayChangePct) * 100, 2) }}%)</span>
                        </p>
                    @endif
                    @if ($canToggleCurrency)
                        <div class="mt-2 flex justify-end" dir="ltr">
                            <flux:button.group>
                                <flux:button size="xs" :variant="$showBaseCurrency ? 'outline' : 'primary'"
                                    wire:click="setCurrency(false)" :aria-label="__('Show prices in :currency', ['currency' => $asset->currency])">
                                    {{ $asset->currency }}</flux:button>
                                <flux:button size="xs" :variant="$showBaseCurrency ? 'primary' : 'outline'"
                                    wire:click="setCurrency(true)" :aria-label="__('Show prices in :currency', ['currency' => config('mahafeth.base_currency')])">
                                    {{ config('mahafeth.base_currency') }}</flux:button>
                            </flux:button.group>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-3">
        {{-- Main column --}}
        <div class="stagger-children flex flex-col gap-6 lg:col-span-2">
            @if ($showChart)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                            {{ __('Market Chart') }}</flux:heading>
                        <a class="text-xs text-neutral-400 hover:underline" href="https://www.tradingview.com/"
                            target="_blank" rel="noopener noreferrer">{{ __('Chart by TradingView') }}</a>
                    </div>
                    {{-- The query theme= only skins the chart toolbar; the page
                         canvas reads colorTheme from the fragment JSON, so both
                         must carry it. The theme swap changes the query string
                         too, forcing a real iframe reload (fragment-only changes
                         are same-document navigations the widget ignores). --}}
                    <div class="mt-4" wire:ignore>
                        <iframe
                            data-src="https://s.tradingview.com/widgetembed/?symbol={{ urlencode($tradingViewSymbol) }}&interval=D&theme=__THEME__&style=1&locale={{ app()->getLocale() === 'ar' ? 'ar_AE' : 'en' }}&hide_side_toolbar=1&allow_symbol_change=0&withdateranges=1&hide_volume=0#%7B%22colorTheme%22%3A%22__THEME__%22%7D"
                            class="h-[26rem] w-full rounded-lg border-0 sm:h-[30rem]" loading="lazy" title="TradingView"
                            x-data
                            x-effect="$el.src = $el.dataset.src.replaceAll('__THEME__', $flux.dark ? 'dark' : 'light')"></iframe>
                    </div>
                </div>
            @endif

            {{-- Performance from our own price history --}}
            @if ($price !== null)
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Performance') }}</flux:heading>
                    <div class="mt-4 grid grid-cols-4 gap-4">
                        @foreach ($returns as $window => $return)
                            <div class="text-center">
                                <flux:text class="text-xs">{{ $window }}</flux:text>
                                <flux:heading
                                    class="{{ ($return ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                                    dir="ltr">
                                    {{ $return !== null ? ($return >= 0 ? '+' : '−').number_format(abs($return) * 100, 1).'%' : '—' }}
                                </flux:heading>
                            </div>
                        @endforeach
                    </div>

                    @if ($week52Position !== null)
                        <div class="mt-6">
                            <div class="flex items-center justify-between">
                                <flux:text class="text-xs">{{ __('52-Week Range') }}</flux:text>
                            </div>
                            <div class="relative mt-2 h-1.5 w-full rounded-full bg-neutral-100 dark:bg-zinc-800" dir="ltr">
                                <div class="bar-fill bar-grow h-full rounded-full bg-gradient-to-r from-red-400 via-amber-400 to-emerald-500" style="width: {{ round($week52Position * 100) }}%"></div>
                                <span class="absolute top-1/2 size-3 -translate-y-1/2 rounded-full border-2 border-white bg-zinc-800 dark:border-zinc-900 dark:bg-white"
                                    style="left: calc({{ round($week52Position * 100) }}% - 6px)"></span>
                            </div>
                            <div class="mt-1.5 flex justify-between" dir="ltr">
                                <flux:text class="text-xs tabular-nums">{{ $currencySymbol }} {{ number_format($week52Low, 2) }}</flux:text>
                                <flux:text class="text-xs tabular-nums">{{ $currencySymbol }} {{ number_format($week52High, 2) }}</flux:text>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if ($showFundamentals)
                <livewire:instruments.fundamentals :symbol="$asset->symbol" lazy.bundle />
            @endif

            {{-- Related disclosures --}}
            @if ($filings->isNotEmpty())
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Company Disclosures') }}</flux:heading>
                    <div class="mt-4 space-y-4">
                        @foreach ($filings as $filing)
                            <div class="border-t border-neutral-100 pt-3 first:border-t-0 first:pt-0 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm">{{ $filing->typeLabel() }}</flux:badge>
                                    <flux:text class="text-xs">
                                        {{ $filing->source }} &bull; {{ $filing->published_at->diffForHumans() }}</flux:text>
                                </div>
                                <flux:heading class="mt-1.5 leading-snug" size="sm">
                                    @if ($filing->url)
                                        <a class="hover:underline" href="{{ $filing->url }}" target="_blank"
                                            rel="noopener noreferrer">{{ $filing->localizedHeadline() }}</a>
                                    @else
                                        {{ $filing->localizedHeadline() }}
                                    @endif
                                </flux:heading>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Related news --}}
            @if ($news->isNotEmpty())
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Related News') }}</flux:heading>
                    <div class="mt-4 space-y-4">
                        @foreach ($news as $item)
                            <div class="border-t border-neutral-100 pt-3 first:border-t-0 first:pt-0 dark:border-zinc-800">
                                <flux:text class="text-xs">
                                    {{ $item->source }} &bull; {{ $item->published_at->diffForHumans() }}</flux:text>
                                <flux:heading class="mt-1 leading-snug" size="sm">
                                    @if ($item->url)
                                        <a class="hover:underline" href="{{ $item->url }}" target="_blank"
                                            rel="noopener noreferrer">{{ $item->localizedHeadline() }}</a>
                                    @else
                                        {{ $item->localizedHeadline() }}
                                    @endif
                                </flux:heading>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Side column --}}
        <div class="stagger-children flex flex-col gap-6">
            {{-- Pre-trade simulation --}}
            <livewire:instruments.what-if :symbol="$asset->symbol" :owned="true" />

            {{-- Position --}}
            <div class="card p-5">
                <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                    {{ __('Your Position') }}</flux:heading>
                <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <flux:text class="text-xs">{{ __('Quantity') }}</flux:text>
                        <flux:heading dir="ltr">{{ number_format($quantity, 2) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-xs">{{ __('Avg Cost') }}</flux:text>
                        <flux:heading dir="ltr">{{ $avgCost !== null ? $currencySymbol.' '.number_format($avgCost, 2) : '—' }}</flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-xs">{{ __('Value') }}</flux:text>
                        <flux:heading dir="ltr">{{ $value !== null ? $currencySymbol.' '.number_format($value, 0) : '—' }}</flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-xs">{{ __('Unrealized P/L') }}</flux:text>
                        <flux:heading
                            class="{{ ($pl ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            dir="ltr">
                            {{ $pl !== null ? ($pl >= 0 ? '+' : '−').$currencySymbol.' '.number_format(abs($pl), 0).' ('.number_format(($plPct ?? 0) * 100, 1).'%)' : '—' }}
                        </flux:heading>
                    </div>
                    @if (round($realized, 2) != 0.0)
                        <div>
                            <flux:tooltip :content="__('Profit or loss you locked in by selling shares of this holding.')">
                                <flux:text class="text-xs">{{ __('Realized P/L') }}</flux:text>
                            </flux:tooltip>
                            <flux:heading
                                class="{{ $realized >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                                dir="ltr">
                                {{ ($realized >= 0 ? '+' : '−').$currencySymbol.' '.number_format(abs($realized), 0) }}
                            </flux:heading>
                        </div>
                    @endif
                </div>

                <flux:button class="mt-5 w-full" size="sm" variant="primary" icon="chat-bubble-left-right"
                    :href="route('advisor', ['ask' => $askPrompt])" wire:navigate>
                    {{ __('Ask Mahafeth AI about this holding') }}</flux:button>
            </div>

            {{-- Portfolio context: the part no market app can show --}}
            @if ($weight !== null)
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('In Your Portfolio') }}</flux:heading>
                    <div class="mt-4">
                        <div class="flex items-center justify-between">
                            <flux:text class="text-xs">{{ __('Portfolio Weight') }}</flux:text>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                {{ Number::percentage($weight * 100, 1) }}</flux:text>
                        </div>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
                            <div class="bar-fill bar-grow h-full bg-teal-600 dark:bg-teal-400" style="width: {{ round($weight * 100) }}%"></div>
                        </div>
                    </div>
                    @if ($sectorExposure !== null && $asset->sector !== null)
                        <flux:text class="mt-3 text-xs">
                            {{ __('Your :sector exposure is :weight of your portfolio.', ['sector' => __($asset->sector), 'weight' => Number::percentage($sectorExposure * 100, 1)]) }}
                        </flux:text>
                    @endif
                    @if ($asset->shariah_status === ShariahStatus::NonCompliant)
                        <flux:text class="mt-2 text-xs !text-red-600 dark:!text-red-400">
                            {{ __('This holding is flagged as non-compliant in your Shariah screening.') }}
                        </flux:text>
                    @endif
                </div>
            @endif

            @if ($showFundamentals)
                <livewire:instruments.analyst-panel :symbol="$asset->symbol" lazy.bundle />
            @endif

            {{-- Transactions from the user's synced accounts --}}
            @if ($transactions->isNotEmpty())
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Recent Transactions') }}</flux:heading>
                    <div class="mt-4 space-y-3">
                        @foreach ($transactions as $transaction)
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <flux:badge size="sm"
                                        :color="match ($transaction->type) {
                                            \App\Enums\TransactionType::Buy => 'emerald',
                                            \App\Enums\TransactionType::Sell => 'red',
                                            default => 'zinc',
                                        }">
                                        {{ $transaction->type->label() }}</flux:badge>
                                    <flux:text class="text-xs">
                                        {{ $transaction->executed_at->diffForHumans() }}</flux:text>
                                </span>
                                <flux:text class="text-sm tabular-nums !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ number_format($transaction->quantity, 2) }} × {{ number_format($transaction->price, 2) }}
                                </flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
