<?php

use App\Enums\ConnectionStatus;
use App\Models\Asset;
use App\Services\Markets\SymbolSearch;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The discovery home: instrument search front and center, the symbols
 * the user recently looked up, and today's market movers below.
 */
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
            'recent' => $this->recentlyViewed(),
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

    /**
     * Symbols the user recently opened from Explore. Only symbols live in
     * the session; display names resolve at render time so they follow
     * the current locale and any catalog updates.
     *
     * @return list<array{symbol: string, name: string}>
     */
    private function recentlyViewed(): array
    {
        $symbols = session('explore.recent', []);

        if ($symbols === []) {
            return [];
        }

        $assets = Asset::whereIn('symbol', $symbols)->get()->keyBy('symbol');

        return array_map(fn (string $symbol): array => [
            'symbol' => $symbol,
            'name' => $assets->get($symbol)?->localizedName() ?? $symbol,
        ], $symbols);
    }
}; ?>

<div class="stagger-children relative mx-auto flex w-full max-w-3xl flex-col gap-6">
    @include('partials.page-glow')
    <div>
        <flux:heading size="xl">{{ __('Explore') }}</flux:heading>
        <flux:text class="mt-1 text-balance">{{ __('Search any instrument and follow what the market is doing today.') }}</flux:text>
    </div>

    {{-- Search --}}
    <div class="card">
        <div class="p-4 {{ trim($query) !== '' ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
            <flux:input icon="magnifying-glass" :placeholder="__('Search any stock, fund, or crypto…')"
                wire:model.live.debounce.350ms="query" autofocus clearable />
        </div>

        @if (trim($query) !== '')
            <div class="max-h-96 overflow-y-auto p-2" wire:loading.class="opacity-60" wire:target="query">
                @if ($owned->isNotEmpty())
                    <flux:text class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-widest">
                        {{ __('Your Holdings') }}</flux:text>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($owned as $asset)
                            <a class="flex items-center gap-3 px-3 py-2.5 hover:bg-zinc-100 dark:hover:bg-zinc-700/50"
                                href="{{ route('holdings.detail', $asset->symbol) }}" wire:navigate
                                wire:key="owned-{{ $asset->symbol }}">
                                <flux:avatar size="sm" color="auto" :name="$asset->symbol"
                                    :initials="mb_substr($asset->symbol, 0, 2)" />
                                <span class="min-w-0 flex-1">
                                    {{-- bdi isolates Latin names inside the RTL layout
                                         (and vice versa) so punctuation stays attached. --}}
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        <bdi>{{ $asset->localizedName() }}</bdi></span>
                                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        <bdi dir="ltr">{{ $asset->symbol }}</bdi></span>
                                </span>
                                <flux:badge class="shrink-0" size="sm">{{ $asset->asset_class->label() }}</flux:badge>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($market !== [])
                    <flux:text class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-widest">
                        {{ __('Markets') }}</flux:text>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($market as $match)
                            @php($typeLabel = match ($match['type']) {
                                'Common Stock' => __('Stock'),
                                'ETF' => __('ETF'),
                                'Digital Currency' => __('Crypto'),
                                'REIT' => __('REIT'),
                                default => null,
                            })
                            <a class="flex items-center gap-3 px-3 py-2.5 hover:bg-zinc-100 dark:hover:bg-zinc-700/50"
                                href="{{ route('explore.instrument', $match['symbol']) }}" wire:navigate
                                wire:key="market-{{ $match['symbol'] }}">
                                <flux:avatar size="sm" color="auto" :name="$match['symbol']"
                                    :initials="mb_substr($match['symbol'], 0, 2)" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        <bdi>{{ $match['name'] }}</bdi></span>
                                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        <bdi dir="ltr">{{ $match['symbol'] }}{{ $match['exchange'] !== '' ? ' • '.$match['exchange'] : '' }}</bdi></span>
                                </span>
                                @if ($typeLabel !== null)
                                    <flux:badge class="shrink-0" size="sm">{{ $typeLabel }}</flux:badge>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @elseif ($owned->isEmpty())
                    <flux:text class="p-4 text-center text-sm" wire:loading.remove wire:target="query">
                        {{ __('No instruments found for :query.', ['query' => trim($query)]) }}</flux:text>
                @endif
            </div>
        @endif
    </div>

    {{-- Recently viewed --}}
    @if ($recent !== [])
        <div>
            <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                {{ __('Recently Viewed') }}</flux:heading>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($recent as $item)
                    <a class="flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-sm transition-transform active:scale-95 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                        href="{{ route('explore.instrument', $item['symbol']) }}" wire:navigate
                        wire:key="recent-{{ $item['symbol'] }}">
                        <span class="font-medium text-zinc-800 dark:text-white" dir="ltr">{{ $item['symbol'] }}</span>
                        @if ($item['name'] !== $item['symbol'])
                            <span class="max-w-40 truncate text-zinc-500 dark:text-zinc-400"><bdi>{{ $item['name'] }}</bdi></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Today's market movers --}}
    <livewire:explore.movers lazy />
</div>
