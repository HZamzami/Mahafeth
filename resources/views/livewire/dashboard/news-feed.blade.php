<?php

use App\Models\NewsItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    private const MAX_ITEMS = 3;

    /**
     * News items relevant to the user's actual holdings, each with a
     * "why this matters to you" line derived from real portfolio weights.
     */
    public function with(): array
    {
        $metrics = Auth::user()->latestSnapshot()?->metrics;

        $weights = $metrics['weights'] ?? [];
        $sectorWeights = $metrics['allocations']['sector'] ?? [];

        $items = NewsItem::query()
            ->latest('published_at')
            ->get()
            ->map(function (NewsItem $item) use ($weights, $sectorWeights): ?array {
                $relevance = $this->relevance($item, $weights, $sectorWeights);

                return $relevance === null ? null : [
                    'item' => $item,
                    'why' => $relevance,
                    'ask' => __('Explain this news and what it means for my portfolio: ":headline"', [
                        'headline' => $item->localizedHeadline(),
                    ]),
                ];
            })
            ->filter()
            ->take(self::MAX_ITEMS)
            ->values()
            ->all();

        return ['entries' => $items];
    }

    /**
     * Why this item matters to this user — or null when it doesn't.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $sectorWeights
     */
    private function relevance(NewsItem $item, array $weights, array $sectorWeights): ?string
    {
        $heldSymbols = array_values(array_intersect($item->symbols, array_keys($weights)));

        if ($heldSymbols !== []) {
            $totalWeight = array_sum(array_map(fn (string $symbol): float => $weights[$symbol], $heldSymbols));

            return __('You hold :weight of your portfolio in :symbols.', [
                'weight' => Number::percentage($totalWeight * 100, 1),
                'symbols' => implode(', ', $heldSymbols),
            ]);
        }

        foreach ($item->sectors ?? [] as $sector) {
            if (isset($sectorWeights[$sector])) {
                return __('Your :sector exposure is :weight of your portfolio.', [
                    'sector' => __($sector),
                    'weight' => Number::percentage($sectorWeights[$sector] * 100, 1),
                ]);
            }
        }

        return null;
    }
}; ?>

<div class="shrink-0 space-y-4 card p-5">
    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
        {{ __('Market Context') }}
    </flux:heading>

    @forelse ($entries as $entry)
        <div
            class="flex gap-4 rounded-lg border border-neutral-200/60 bg-neutral-50 p-4 dark:border-neutral-700/60 dark:bg-zinc-800/50">
            <div
                class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-200 dark:bg-zinc-800">
                <flux:icon.newspaper class="size-5 text-neutral-400 dark:text-zinc-500" />
            </div>
            <div class="min-w-0">
                <flux:heading class="leading-snug" size="sm">
                    @if ($entry['item']->url)
                        <a class="hover:underline" href="{{ $entry['item']->url }}" target="_blank"
                            rel="noopener noreferrer">{{ $entry['item']->localizedHeadline() }}</a>
                    @else
                        {{ $entry['item']->localizedHeadline() }}
                    @endif
                </flux:heading>
                <flux:text class="mt-1 text-xs">
                    {{ $entry['item']->source }} &bull; {{ $entry['item']->published_at->diffForHumans() }}
                </flux:text>
                <flux:text class="mt-1.5 flex items-center gap-1 text-xs !text-teal-700 dark:!text-teal-300">
                    <flux:icon.sparkles class="size-3.5 shrink-0" /> {{ $entry['why'] }}
                </flux:text>
                <flux:button class="mt-2" size="xs" icon="chat-bubble-left-right"
                    :href="route('advisor', ['ask' => $entry['ask']])" wire:navigate>
                    {{ __('Ask Mahafeth AI') }}</flux:button>
            </div>
        </div>
    @empty
        <flux:text class="text-sm">{{ __('No news relevant to your holdings right now.') }}</flux:text>
    @endforelse
</div>
