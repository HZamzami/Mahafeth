<?php

use App\Contracts\FundamentalsProvider;
use App\Models\Asset;
use App\Services\Markets\CompanySummaryTranslator;
use Livewire\Volt\Component;

/**
 * Main-column fundamentals for one equity: quarterly financials and the
 * company profile. Loaded lazily because the data comes from an external
 * API on first view; when it is unavailable the section renders empty.
 */
new class extends Component {
    public string $symbol;

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    public function with(): array
    {
        $fundamentals = app(FundamentalsProvider::class)->fetch($this->symbol);

        $summary = $fundamentals['profile']['summary'] ?? null;
        $awaitingTranslation = false;

        // The Arabic translation runs on the queue; serve English now and
        // poll until the translated version is cached so the financial data
        // beside it never waits on the Claude call.
        if ($summary !== null && app()->getLocale() === 'ar') {
            $translation = app(CompanySummaryTranslator::class)->toArabic($this->symbol, $summary);
            $summary = $translation['text'];
            $awaitingTranslation = $translation['pending'];
        }

        return [
            'fundamentals' => $fundamentals,
            'summary' => $summary,
            'awaitingTranslation' => $awaitingTranslation,
            'financialCurrencySymbol' => Asset::symbolForCurrency($fundamentals['financialCurrency'] ?? null),
        ];
    }
}; ?>

<div class="flex flex-col gap-6 empty:hidden" @if ($awaitingTranslation) wire:poll.3s @endif>
    @if ($fundamentals !== null)
        @if ($fundamentals['headline']['revenue'] !== null || $fundamentals['quarters'] !== [])
            <x-instruments.financials-card :headline="$fundamentals['headline']"
                :quarters="$fundamentals['quarters']" :currency-symbol="$financialCurrencySymbol" />
        @endif

        @if ($summary !== null)
            <x-instruments.about-card :profile="$fundamentals['profile']" :summary="$summary" />
        @endif
    @endif
</div>
