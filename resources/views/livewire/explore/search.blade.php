<?php

use App\Enums\ConnectionStatus;
use App\Models\Asset;
use App\Services\Markets\SymbolSearch;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $query = '';

    public function with(): array
    {
        $owned = $this->ownedMatches();

        $market = mb_strlen(trim($this->query)) >= 2
            ? array_values(array_filter(
                app(SymbolSearch::class)->search($this->query),
                fn (array $match): bool => ! $owned->contains('symbol', $match['symbol']),
            ))
            : [];

        return [
            'owned' => $owned,
            'market' => $market,
        ];
    }

    /**
     * The user's own instruments matching the query, straight from the
     * local catalog so they appear instantly while the market lookup runs.
     */
    private function ownedMatches()
    {
        $query = trim($this->query);

        if ($query === '') {
            return collect();
        }

        return Asset::query()
            ->whereHas('holdings.account.connection', fn ($builder) => $builder
                ->whereBelongsTo(Auth::user())
                ->where('status', ConnectionStatus::Connected))
            ->where(fn ($builder) => $builder
                ->where('symbol', 'like', "%{$query}%")
                ->orWhere('name', 'like', "%{$query}%")
                ->orWhere('name_ar', 'like', "%{$query}%"))
            ->orderBy('symbol')
            ->limit(6)
            ->get();
    }
}; ?>

<flux:modal name="instrument-search" class="w-full !p-0 sm:max-w-lg" @close="$wire.set('query', '')">
    <div class="flex flex-col">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:input icon="magnifying-glass" :placeholder="__('Search any stock, fund, or crypto…')"
                wire:model.live.debounce.350ms="query" autofocus clearable />
        </div>

        <div class="max-h-96 overflow-y-auto p-2" wire:loading.class="opacity-60" wire:target="query">
            @if (trim($query) === '')
                <flux:text class="p-4 text-center text-sm">
                    {{ __('Type a ticker or company name — Apple, 2222.SR, BTC…') }}</flux:text>
            @else
                @if ($owned->isNotEmpty())
                    <flux:text class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-widest">
                        {{ __('Your Holdings') }}</flux:text>
                    @foreach ($owned as $asset)
                        <a class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 hover:bg-zinc-100 dark:hover:bg-zinc-700/50"
                            href="{{ route('holdings.detail', $asset->symbol) }}" wire:navigate
                            wire:key="owned-{{ $asset->symbol }}">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $asset->localizedName() }}</span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400" dir="ltr">
                                    {{ $asset->symbol }}</span>
                            </span>
                            <flux:badge size="sm">{{ $asset->asset_class->label() }}</flux:badge>
                        </a>
                    @endforeach
                @endif

                @if ($market !== [])
                    <flux:text class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-widest">
                        {{ __('Markets') }}</flux:text>
                    @foreach ($market as $match)
                        <a class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 hover:bg-zinc-100 dark:hover:bg-zinc-700/50"
                            href="{{ route('explore.instrument', $match['symbol']) }}" wire:navigate
                            wire:key="market-{{ $match['symbol'] }}">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $match['name'] }}</span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400" dir="ltr">
                                    {{ $match['symbol'] }} &bull; {{ $match['exchange'] }}</span>
                            </span>
                            @if ($match['country'] !== '')
                                <flux:text class="shrink-0 text-xs">{{ $match['country'] }}</flux:text>
                            @endif
                        </a>
                    @endforeach
                @elseif ($owned->isEmpty())
                    <flux:text class="p-4 text-center text-sm" wire:loading.remove wire:target="query">
                        {{ __('No instruments found for :query.', ['query' => trim($query)]) }}</flux:text>
                @endif
            @endif
        </div>
    </div>
</flux:modal>
