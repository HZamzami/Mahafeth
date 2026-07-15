<?php

use App\Enums\ConnectionStatus;
use App\Models\Institution;
use App\Services\DataFreshness;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    /**
     * Summarize the user's Open Banking connections.
     */
    public function with(): array
    {
        $connections = Auth::user()->connections()->get();

        return [
            'connectedCount' => $connections->where('status', ConnectionStatus::Connected)->count(),
            'institutionsCount' => Institution::count(),
            'lastSyncedAt' => $connections->max('last_synced_at'),
            'freshness' => app(DataFreshness::class)->forUser(Auth::user()),
        ];
    }
}; ?>

<div class="shrink-0 card p-5">
    <div class="mb-4 flex items-start justify-between">
        <div>
            <flux:heading size="lg">{{ __('Open Banking') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Active connections') }}</flux:text>
        </div>
        <flux:icon.cloud-arrow-up
            class="size-6 {{ $connectedCount > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-300 dark:text-zinc-600' }}" />
    </div>
    <div
        class="flex items-center gap-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-zinc-800">
        <div class="rounded-lg bg-teal-100 p-2 dark:bg-teal-500/20">
            <flux:icon.building-library class="size-5 text-teal-700 dark:text-teal-300" />
        </div>
        <div class="flex-1">
            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                {{ __(':count Sources', ['count' => $connectedCount . '/' . $institutionsCount]) }}
            </flux:text>
            <flux:text class="text-xs">
                {{ $lastSyncedAt !== null ? __('Last sync: :time', ['time' => $lastSyncedAt->diffForHumans()]) : __('No sources connected yet') }}
            </flux:text>
        </div>
    </div>
    @if ($freshness !== null)
        <div class="mt-3 space-y-1">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs">
                    {{ __('Prices as of :date', ['date' => $freshness['prices_as_of']->translatedFormat('j M')]) }}
                </flux:text>
                @if ($freshness['stale_prices'])
                    <flux:badge color="amber" size="sm">{{ __('Stale') }}</flux:badge>
                @endif
            </div>
            @if ($freshness['fx_fetched_at'] !== null)
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs">
                        {{ __('FX rates from :time', ['time' => $freshness['fx_fetched_at']->diffForHumans()]) }}
                    </flux:text>
                    @if ($freshness['stale_fx'])
                        <flux:badge color="amber" size="sm">{{ __('Stale') }}</flux:badge>
                    @endif
                </div>
            @endif
        </div>
    @endif
    <flux:button class="mt-4 w-full" size="sm" variant="outline" :href="route('connections')" wire:navigate>
        {{ __('Manage Sources') }}</flux:button>
</div>
