<?php

use App\Actions\GenerateInsights;
use App\Models\AiInsight;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Generate (or regenerate) the AI explanation for the latest snapshot.
     */
    public function generate(GenerateInsights $generateInsights): void
    {
        $generateInsights->handle(Auth::user(), app()->getLocale());
    }

    public function with(): array
    {
        $snapshot = Auth::user()->latestSnapshot();

        $insight = $snapshot === null ? null : AiInsight::query()
            ->where('portfolio_snapshot_id', $snapshot->id)
            ->where('locale', app()->getLocale())
            ->first();

        return [
            'hasSnapshot' => $snapshot !== null,
            'insight' => $insight,
        ];
    }
}; ?>

<div
    class="flex grow flex-col card p-5">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-lg bg-teal-100 dark:bg-teal-500/20">
                <flux:icon.sparkles class="size-5 text-teal-700 dark:text-teal-300" />
            </div>
            <flux:heading class="!text-teal-700 dark:!text-teal-300" size="lg">{{ __('Mahafeth AI') }}
            </flux:heading>
        </div>

        @if ($insight !== null)
            <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="generate"
                wire:loading.attr="disabled" :tooltip="__('Regenerate')" />
        @endif
    </div>

    <div wire:loading.flex wire:target="generate" class="grow flex-col items-center justify-center gap-3 py-10">
        <flux:icon.loading class="size-6 text-teal-700 dark:text-teal-300" />
        <flux:text class="text-sm">{{ __('Analyzing your portfolio…') }}</flux:text>
    </div>

    <div wire:loading.remove wire:target="generate" class="flex grow flex-col">
        @if ($insight !== null)
            <flux:callout color="blue" icon="light-bulb">
                <flux:callout.heading>{{ __('Executive Summary') }}</flux:callout.heading>
                <flux:callout.text>{{ $insight->summary }}</flux:callout.text>
            </flux:callout>

            <flux:text class="mb-3 mt-6 text-xs font-medium uppercase tracking-widest">{{ __('Action Plan') }}
            </flux:text>
            <div class="grow space-y-3">
                @foreach ($insight->recommendations as $recommendation)
                    <div class="rounded-lg border border-neutral-200/60 bg-neutral-50 p-3 dark:border-neutral-700/60 dark:bg-zinc-800/50">
                        <div class="flex items-center justify-between gap-2">
                            <flux:heading size="sm">{{ $recommendation['title'] }}</flux:heading>
                            <flux:badge size="sm" inset="top bottom"
                                :color="['high' => 'red', 'medium' => 'amber', 'low' => 'zinc'][$recommendation['priority']] ?? 'zinc'">
                                {{ __(ucfirst($recommendation['priority'])) }}</flux:badge>
                        </div>
                        <flux:text class="mt-1 text-sm">{{ $recommendation['body'] }}</flux:text>
                    </div>
                @endforeach
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
</div>
