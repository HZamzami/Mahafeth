<?php

use App\Models\Holding;
use App\Models\PriceHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Compute headline portfolio figures from the user's synced holdings.
     */
    public function with(): array
    {
        $holdings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query->whereBelongsTo(Auth::user()))
            ->get();

        $closes = PriceHistory::latestCloses($holdings->pluck('asset_id')->unique()->values()->all());

        $valued = $holdings->map(fn (Holding $holding) => [
            'holding' => $holding,
            'value' => $holding->quantity * ($closes[$holding->asset_id] ?? $holding->avg_cost),
        ]);

        $totalValue = $valued->sum('value');
        $largest = $valued->sortByDesc('value')->first();

        $sectors = $valued
            ->groupBy(fn (array $entry) => $entry['holding']->asset->sector ?? $entry['holding']->asset->asset_class->label())
            ->map(fn ($entries) => $entries->sum('value'))
            ->sortDesc();

        return [
            'totalValue' => $totalValue,
            'holdingsCount' => $holdings->count(),
            'accountsCount' => $holdings->pluck('account_id')->unique()->count(),
            'largestHolding' => $largest['holding'] ?? null,
            'largestHoldingWeight' => $totalValue > 0 && $largest !== null ? $largest['value'] / $totalValue : 0,
            'largestSector' => $sectors->keys()->first(),
            'largestSectorWeight' => $totalValue > 0 ? ($sectors->first() ?? 0) / $totalValue : 0,
        ];
    }
}; ?>

<div class="grid auto-rows-fr gap-4 md:grid-cols-4">
    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Total Portfolio Value') }}
            </flux:text>
            <flux:heading class="!text-emerald-600 dark:!text-emerald-400" size="xl" dir="ltr">
                ${{ Number::abbreviate($totalValue, 1) }}</flux:heading>
        </div>
        <flux:text class="mt-2 flex items-center gap-1 text-xs">
            <flux:icon.building-library class="size-4" /> {{ __('Unified across all connected sources') }}
        </flux:text>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Holdings') }}</flux:text>
            <flux:heading size="xl" dir="ltr">{{ $holdingsCount }}</flux:heading>
        </div>
        <flux:text class="mt-2 text-xs">{{ __('Across :count accounts', ['count' => $accountsCount]) }}</flux:text>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Holding') }}</flux:text>
            <flux:heading size="xl">
                {{ $largestHolding !== null ? $largestHolding->asset->localizedName() . ' (' . $largestHolding->asset->symbol . ')' : '—' }}
            </flux:heading>
        </div>
        <flux:text class="mt-2 text-xs">
            {{ __(':percent of Portfolio', ['percent' => Number::percentage($largestHoldingWeight * 100, 1)]) }}</flux:text>
    </div>

    <div
        class="flex flex-col justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
        <div>
            <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Largest Sector') }}</flux:text>
            <flux:heading class="leading-tight" size="xl">{{ $largestSector !== null ? __($largestSector) : '—' }}
            </flux:heading>
        </div>
        <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-zinc-800">
            <div class="h-full bg-blue-500 dark:bg-blue-400" style="width: {{ round($largestSectorWeight * 100) }}%">
            </div>
        </div>
    </div>
</div>
