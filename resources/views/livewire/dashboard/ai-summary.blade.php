<?php

use App\Jobs\GenerateInsightsJob;
use App\Models\AiInsight;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Queue generation of the AI explanation for the latest snapshot. The
     * cache flag lets the card keep showing "analyzing" across reloads and
     * navigation while the job runs; ShouldBeUnique dedupes double-clicks.
     */
    public function generate(): void
    {
        $user = Auth::user();
        $locale = app()->getLocale();

        // Nothing to explain before the first analysis; without this a
        // crafted request pins the card in the analyzing state for the
        // flag's whole TTL.
        if ($user->latestSnapshot() === null) {
            return;
        }

        Cache::forget(GenerateInsightsJob::failedCacheKey($user, $locale));
        Cache::put(GenerateInsightsJob::cacheKey($user, $locale), true, now()->addMinutes(5));
        GenerateInsightsJob::dispatch($user, $locale);
    }

    public function with(): array
    {
        $snapshot = Auth::user()->latestSnapshot();

        $insight = $snapshot === null ? null : AiInsight::query()
            ->where('portfolio_snapshot_id', $snapshot->id)
            ->where('locale', app()->getLocale())
            ->first();

        $isGenerating = Cache::has(GenerateInsightsJob::cacheKey(Auth::user(), app()->getLocale()));

        return [
            'hasSnapshot' => $snapshot !== null,
            'insight' => $insight,
            'isGenerating' => $isGenerating,
            'hasFailed' => ! $isGenerating && Cache::has(GenerateInsightsJob::failedCacheKey(Auth::user(), app()->getLocale())),
            // Same-day re-analysis updates the snapshot row in place, so an
            // insight older than its snapshot explains numbers that changed.
            'isStale' => $insight !== null && $insight->updated_at->lt($snapshot->updated_at),
        ];
    }
}; ?>

<div class="flex grow flex-col card p-5" @if ($isGenerating) wire:poll.3s @endif>
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-lg bg-teal-100 dark:bg-teal-500/20">
                <flux:icon.sparkles class="size-5 text-teal-700 dark:text-teal-300" />
            </div>
            <flux:heading class="!text-teal-700 dark:!text-teal-300" size="lg">{{ __('Mahafeth AI') }}
            </flux:heading>
        </div>

        @if ($insight !== null)
            <flux:button size="sm" variant="subtle" :icon="$isGenerating ? 'loading' : 'arrow-path'"
                wire:click="generate" wire:loading.attr="disabled" :disabled="$isGenerating"
                :tooltip="__('Regenerate')" :aria-label="__('Regenerate')" />
        @endif
    </div>

    @if ($hasFailed)
        <flux:callout class="mb-4" color="red" icon="exclamation-triangle" inline>
            <flux:callout.text>
                {{ __('Insight generation failed — please try again.') }}
                <flux:link class="cursor-pointer" wire:click="generate">{{ __('Regenerate') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($insight !== null)
        @if ($isStale && ! $isGenerating)
            <flux:callout class="mb-4" color="amber" icon="exclamation-triangle" inline>
                <flux:callout.text>
                    {{ __('Your analysis has changed since this was generated.') }}
                    <flux:link class="cursor-pointer" wire:click="generate">{{ __('Regenerate') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:callout color="blue" icon="light-bulb">
            <flux:callout.heading>{{ __('Executive Summary') }}</flux:callout.heading>
            <flux:callout.text>{{ $insight->summary }}</flux:callout.text>
        </flux:callout>

        @if (($insight->recommendations[0] ?? null) !== null)
            @php($top = $insight->recommendations[0])
            <flux:text class="mb-3 mt-6 text-xs font-medium uppercase tracking-widest">
                {{ __('Top recommendation') }}</flux:text>
            <div
                class="rounded-lg border border-neutral-200/60 bg-neutral-50 p-3 dark:border-neutral-700/60 dark:bg-zinc-800/50">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="sm">{{ $top['title'] }}</flux:heading>
                    <flux:badge size="sm" inset="top bottom"
                        :color="['high' => 'red', 'medium' => 'amber', 'low' => 'zinc'][$top['priority']] ?? 'zinc'">
                        {{ __(ucfirst($top['priority'])) }}</flux:badge>
                </div>
                <flux:text class="mt-1 text-sm">{{ $top['body'] }}</flux:text>
            </div>
        @endif

        <div class="mt-auto pt-4">
            <flux:button class="w-full" variant="primary" icon="chat-bubble-left-right" :href="route('advisor')"
                wire:navigate>
                {{ __('Ask Mahafeth AI') }}</flux:button>
            <flux:text class="mt-2 text-center text-xs">
                {{ __('AI-generated analysis can be inaccurate — not licensed financial advice.') }}</flux:text>
        </div>
    @elseif ($isGenerating)
        <div class="flex grow flex-col items-center justify-center gap-3 py-10 text-center">
            <flux:icon.loading class="size-6 text-teal-700 dark:text-teal-300" />
            <flux:text class="text-sm">{{ __('Analyzing your portfolio…') }}</flux:text>
            <flux:text class="max-w-56 text-xs">
                {{ __('This runs in the background — feel free to keep browsing.') }}</flux:text>
        </div>
    @elseif ($hasSnapshot)
        <div class="flex grow flex-col items-center justify-center gap-4 py-10 text-center">
            <flux:text class="max-w-64 text-sm">
                {{ __('Get a plain-language explanation of your scores and a personalized action plan.') }}
            </flux:text>
            <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled">
                {{ __('Generate Insights') }}</flux:button>
        </div>
    @else
        <div class="flex grow flex-col items-center justify-center gap-3 py-10 text-center">
            <flux:text class="max-w-56 text-sm">
                {{ __('Connect your accounts and Mahafeth AI will explain your portfolio in plain language.') }}
            </flux:text>
            <flux:button size="sm" variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @endif
</div>
