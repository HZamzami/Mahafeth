<?php

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\Asset;
use App\Models\Holding;
use App\Services\Markets\TradingViewSymbol;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $symbol;

    public ?Asset $asset = null;

    /**
     * Owned instruments have a richer canonical page, so land there. This
     * page exists for everything the user does not hold yet.
     */
    public function mount(string $symbol): void
    {
        $this->symbol = strtoupper($symbol);
        $this->asset = Asset::where('symbol', $this->symbol)->first();

        if ($this->asset !== null && $this->userOwns($this->asset)) {
            $this->redirectRoute('holdings.detail', $this->asset->symbol, navigate: true);
        }
    }

    public function with(): array
    {
        $assetClass = $this->asset?->asset_class;

        return [
            'tradingViewSymbol' => TradingViewSymbol::for($this->symbol, $assetClass),
            // Unknown symbols get the full equity treatment; the widgets
            // degrade gracefully when a pane has no data for them.
            'showTechnicals' => $assetClass === null || in_array($assetClass, [AssetClass::Equity, AssetClass::Crypto], true),
            'showFundamentals' => $assetClass === null || $assetClass === AssetClass::Equity,
            'askPrompt' => __('I am considering :symbol. How would adding it affect my portfolio diversification and risk?', [
                'symbol' => $this->symbol,
            ]),
        ];
    }

    private function userOwns(Asset $asset): bool
    {
        return Holding::whereBelongsTo($asset)
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo(Auth::user())
                ->where('status', ConnectionStatus::Connected))
            ->exists();
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div>
        <flux:button size="sm" variant="ghost" :href="route('holdings.index')" wire:navigate>
            <flux:icon.chevron-left class="size-4 rtl:rotate-180" /> {{ __('Holdings') }}</flux:button>

        <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="xl">{{ $asset?->localizedName() ?? $symbol }}</flux:heading>
                    <flux:text class="text-sm" dir="ltr">{{ $symbol }}</flux:text>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @if ($asset)
                        <flux:badge size="sm">{{ $asset->asset_class->label() }}</flux:badge>
                        @if ($asset->sector)
                            <flux:badge size="sm">{{ __($asset->sector) }}</flux:badge>
                        @endif
                        @if ($asset->shariah_status === ShariahStatus::Compliant)
                            <flux:badge color="emerald" size="sm">{{ $asset->shariah_status->label() }}</flux:badge>
                        @elseif ($asset->shariah_status === ShariahStatus::NonCompliant)
                            <flux:badge color="red" size="sm">{{ $asset->shariah_status->label() }}</flux:badge>
                        @endif
                    @endif
                    <flux:badge color="zinc" size="sm" icon="eye">{{ __('Watching') }}</flux:badge>
                </div>
            </div>
        </div>
    </div>

    <flux:callout icon="information-circle" inline>
        <flux:callout.text>{{ __("You don't hold this asset — data below comes from the market, not your accounts.") }}</flux:callout.text>
        <x-slot name="actions">
            <flux:button size="xs" variant="ghost" icon="chat-bubble-left-right"
                :href="route('advisor', ['ask' => $askPrompt])" wire:navigate>
                {{ __('Ask Mahafeth AI') }}</flux:button>
        </x-slot>
    </flux:callout>

    <div class="grid items-start gap-6 lg:grid-cols-3">
        {{-- Main column --}}
        <div class="flex flex-col gap-6 lg:col-span-2">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Market Chart') }}</flux:heading>
                    <a class="text-xs text-neutral-400 hover:underline" href="https://www.tradingview.com/"
                        target="_blank" rel="noopener noreferrer">{{ __('Chart by TradingView') }}</a>
                </div>
                <div class="mt-4" wire:ignore>
                    <iframe
                        src="https://s.tradingview.com/widgetembed/?symbol={{ urlencode($tradingViewSymbol) }}&interval=D&theme=dark&style=1&locale={{ app()->getLocale() === 'ar' ? 'ar_AE' : 'en' }}&hide_side_toolbar=1&allow_symbol_change=0&withdateranges=1&hide_volume=0"
                        class="h-[26rem] w-full rounded-lg border-0 sm:h-[30rem]" loading="lazy" title="TradingView"
                        x-data
                        x-init="if (! document.documentElement.classList.contains('dark')) $el.src = $el.src.replace('theme=dark', 'theme=light')"></iframe>
                </div>
            </div>

            @if ($showFundamentals)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                            {{ __('Financials') }}</flux:heading>
                        <flux:text class="text-xs !text-neutral-400">TradingView</flux:text>
                    </div>
                    <x-tv-widget class="mt-4 h-96" name="financials"
                        :config="['symbol' => $tradingViewSymbol, 'displayMode' => 'regular']" />
                </div>

                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                            {{ __('About the Company') }}</flux:heading>
                        <flux:text class="text-xs !text-neutral-400">TradingView</flux:text>
                    </div>
                    <x-tv-widget class="mt-4 h-64" name="symbol-profile" :config="['symbol' => $tradingViewSymbol]" />
                </div>
            @endif
        </div>

        {{-- Side column --}}
        <div class="flex flex-col gap-6">
            @if ($showTechnicals)
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                            {{ __('Technical Signal') }}</flux:heading>
                        <flux:text class="text-xs !text-neutral-400">TradingView</flux:text>
                    </div>
                    <x-tv-widget class="mt-4 h-96" name="technical-analysis"
                        :config="['symbol' => $tradingViewSymbol, 'interval' => '1D', 'showIntervalTabs' => true]" />
                    <flux:text class="mt-2 text-center text-xs">
                        {{ __('Signals by TradingView — not investment advice.') }}</flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
