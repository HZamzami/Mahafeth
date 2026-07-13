<?php

use App\Contracts\FundamentalsProvider;
use App\Models\Asset;
use Livewire\Volt\Component;

/**
 * Side-column analyst view for one equity: the buy/hold/sell consensus
 * with price targets, plus the key valuation stats. Shares the cached
 * fundamentals fetch with the main-column component.
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

        return [
            'fundamentals' => $fundamentals,
            'priceCurrencySymbol' => Asset::symbolForCurrency($fundamentals['currency'] ?? null),
        ];
    }
}; ?>

<div class="flex flex-col gap-6 empty:hidden">
    @if ($fundamentals !== null)
        @if ($fundamentals['ratings'] !== null || $fundamentals['priceTarget'] !== null)
            <x-instruments.analyst-card :ratings="$fundamentals['ratings']"
                :price-target="$fundamentals['priceTarget']" :currency-symbol="$priceCurrencySymbol" />
        @endif

        @if (array_filter($fundamentals['stats']) !== [])
            <x-instruments.key-stats-card :stats="$fundamentals['stats']" :currency-symbol="$priceCurrencySymbol" />
        @endif
    @endif
</div>
