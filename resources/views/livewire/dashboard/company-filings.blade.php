<?php

use App\Models\CompanyFiling;
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
     * The latest disclosures from companies the user actually holds,
     * each carrying the weight line and the seeded advisor question.
     */
    public function with(): array
    {
        $weights = Auth::user()->latestSnapshot()?->metrics['weights'] ?? [];

        $entries = CompanyFiling::query()
            ->whereIn('symbol', array_keys($weights))
            ->latest('published_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (CompanyFiling $filing): array => [
                'filing' => $filing,
                'why' => __('You hold :weight of your portfolio in :symbol.', [
                    'weight' => Number::percentage($weights[$filing->symbol] * 100, 1),
                    'symbol' => $filing->symbol,
                ]),
                'ask' => __('Explain this disclosure and what it means for my portfolio: ":headline" (:symbol). Key excerpt: :excerpt', [
                    'headline' => $filing->localizedHeadline(),
                    'symbol' => $filing->symbol,
                    'excerpt' => mb_substr($filing->localizedExcerpt(), 0, 400),
                ]),
            ])
            ->all();

        return ['entries' => $entries];
    }
}; ?>

<div class="shrink-0 space-y-4 card p-5">
    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
        {{ __('Company Disclosures') }}
    </flux:heading>

    @if ($entries !== [])
        <flux:timeline>
            @foreach ($entries as $entry)
                <flux:timeline.item>
                    <flux:timeline.indicator />
                    <flux:timeline.content>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm">{{ $entry['filing']->typeLabel() }}</flux:badge>
                            <flux:text class="text-xs">
                                {{ $entry['filing']->source }} &bull; {{ $entry['filing']->published_at->diffForHumans() }}
                            </flux:text>
                        </div>
                        <flux:heading class="mt-2 leading-snug" size="sm">
                            @if ($entry['filing']->url)
                                <a class="hover:underline" href="{{ $entry['filing']->url }}" target="_blank"
                                    rel="noopener noreferrer">{{ $entry['filing']->localizedHeadline() }}</a>
                            @else
                                {{ $entry['filing']->localizedHeadline() }}
                            @endif
                        </flux:heading>
                        <flux:text class="mt-1.5 flex items-center gap-1 text-xs !text-teal-700 dark:!text-teal-300">
                            <flux:icon.sparkles class="size-3.5 shrink-0" /> {{ $entry['why'] }}
                        </flux:text>
                        <flux:button class="mt-2" size="xs" icon="chat-bubble-left-right"
                            :href="route('advisor', ['ask' => $entry['ask']])" wire:navigate>
                            {{ __('Ask Mahafeth AI') }}</flux:button>
                    </flux:timeline.content>
                </flux:timeline.item>
            @endforeach
        </flux:timeline>
    @else
        <flux:text class="text-sm">{{ __('No recent disclosures from companies you hold.') }}</flux:text>
    @endif
</div>
