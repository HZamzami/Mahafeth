<?php

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\CompanyFiling;
use App\Models\Holding;
use App\Models\NewsItem;
use App\Services\Fx\FxRateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public Asset $asset;

    public function mount(Asset $asset): void
    {
        abort_unless($this->userHoldings($asset)->exists(), 404);

        $this->asset = $asset;
    }

    public function with(): array
    {
        $holdings = $this->userHoldings($this->asset)->get();
        $rate = app(FxRateService::class)->all()[$this->asset->currency] ?? 1.0;

        $quantity = $holdings->sum(fn (Holding $holding): float => (float) $holding->quantity);
        $cost = $holdings->sum(fn (Holding $holding): float => $holding->quantity * $holding->avg_cost * $rate);

        $close = $this->asset->priceHistories()->latest('date')->value('close');
        $price = $close !== null ? $close * $rate : null;
        $value = $price !== null ? $quantity * $price : null;

        return [
            'quantity' => $quantity,
            'avgCost' => $quantity > 0 ? $cost / $quantity : null,
            'price' => $price,
            'value' => $value,
            'pl' => $value !== null ? $value - $cost : null,
            'plPct' => $value !== null && $cost > 0 ? ($value - $cost) / $cost : null,
            'weight' => Auth::user()->latestSnapshot()?->metrics['weights'][$this->asset->symbol] ?? null,
            'tradingViewSymbol' => $this->tradingViewSymbol(),
            'filings' => CompanyFiling::where('symbol', $this->asset->symbol)->latest('published_at')->limit(3)->get(),
            'news' => NewsItem::latest('published_at')->get()
                ->filter(fn (NewsItem $item): bool => in_array($this->asset->symbol, $item->symbols ?? [], true))
                ->take(3),
            'askPrompt' => __('Tell me about my :symbol position: how it affects my portfolio risk, and whether I should trim, hold, or add.', [
                'symbol' => $this->asset->symbol,
            ]),
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

    /**
     * Map our symbols onto TradingView's: Tadawul tickers carry a .SR
     * suffix, crypto trades against USD, US tickers resolve as-is.
     */
    private function tradingViewSymbol(): string
    {
        return match (true) {
            str_ends_with($this->asset->symbol, '.SR') => 'TADAWUL:'.Str::before($this->asset->symbol, '.SR'),
            $this->asset->asset_class === AssetClass::Crypto => Str::before($this->asset->symbol, '-').'USD',
            default => $this->asset->symbol,
        };
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:button size="sm" variant="ghost" :href="route('dashboard')" wire:navigate>
            <flux:icon.chevron-left class="size-4 rtl:rotate-180" /> {{ __('Dashboard') }}</flux:button>

        <div class="mt-3 flex flex-wrap items-center gap-2">
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
            @endif
        </div>
    </div>

    {{-- Position --}}
    <div class="card p-5">
        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
            {{ __('Your Position') }}</flux:heading>
        <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
            <div>
                <flux:text class="text-xs">{{ __('Quantity') }}</flux:text>
                <flux:heading dir="ltr">{{ number_format($quantity, 2) }}</flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Current Price') }}</flux:text>
                <flux:heading dir="ltr">{{ $price !== null ? '⃁ '.number_format($price, 2) : '—' }}</flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Avg Cost') }}</flux:text>
                <flux:heading dir="ltr">{{ $avgCost !== null ? '⃁ '.number_format($avgCost, 2) : '—' }}</flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Value') }}</flux:text>
                <flux:heading dir="ltr">{{ $value !== null ? '⃁ '.number_format($value, 0) : '—' }}</flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Unrealized P/L') }}</flux:text>
                <flux:heading
                    class="{{ ($pl ?? 0) >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                    dir="ltr">
                    {{ $pl !== null ? ($pl >= 0 ? '+' : '−').'⃁ '.number_format(abs($pl), 0).' ('.number_format(($plPct ?? 0) * 100, 1).'%)' : '—' }}
                </flux:heading>
            </div>
            <div>
                <flux:text class="text-xs">{{ __('Portfolio Weight') }}</flux:text>
                <flux:heading dir="ltr">{{ $weight !== null ? Number::percentage($weight * 100, 1) : '—' }}</flux:heading>
            </div>
        </div>

        <flux:button class="mt-5" size="sm" variant="primary" icon="chat-bubble-left-right"
            :href="route('advisor', ['ask' => $askPrompt])" wire:navigate>
            {{ __('Ask Mahafeth AI about this holding') }}</flux:button>
    </div>

    {{-- Market chart --}}
    <div class="card p-5">
        <div class="flex items-center justify-between">
            <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                {{ __('Market Chart') }}</flux:heading>
            <a class="text-xs text-neutral-400 hover:underline" href="https://www.tradingview.com/" target="_blank"
                rel="noopener noreferrer">{{ __('Chart by TradingView') }}</a>
        </div>
        <div class="mt-4" wire:ignore>
            <iframe
                src="https://s.tradingview.com/widgetembed/?symbol={{ urlencode($tradingViewSymbol) }}&interval=D&theme=dark&style=1&locale={{ app()->getLocale() === 'ar' ? 'ar_AE' : 'en' }}&hide_side_toolbar=1&allow_symbol_change=0&withdateranges=1&hide_volume=0"
                class="h-80 w-full rounded-lg border-0 sm:h-96" loading="lazy" title="TradingView" x-data
                x-init="if (! document.documentElement.classList.contains('dark')) $el.src = $el.src.replace('theme=dark', 'theme=light')"></iframe>
        </div>
    </div>

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
