<?php

use App\Support\Changelog;
use Illuminate\Support\Carbon;
use Livewire\Volt\Component;

/**
 * The product changelog: curated release notes so users see the app
 * moving and discover features that silent deploys would hide.
 */
new class extends Component {
    public function with(): array
    {
        return [
            'releases' => Changelog::entries(),
            'latestDate' => Changelog::latestDate(),
        ];
    }
}; ?>

<div class="stagger-children mx-auto flex w-full max-w-3xl flex-col gap-6"
    x-data x-init="localStorage.setItem('mahafeth-changelog-seen', '{{ $latestDate }}')">
    <div>
        <flux:heading size="xl">{{ __("What's New") }}</flux:heading>
        <flux:text class="mt-1 text-balance">{{ __('Everything we shipped recently, in plain language.') }}</flux:text>
    </div>

    @foreach ($releases as $release)
        @php($date = Carbon::parse($release['date']))
        <div class="card p-5" wire:key="release-{{ $release['date'] }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ $date->isoFormat('LL') }}</flux:heading>
                <flux:text class="text-xs">{{ $date->diffForHumans() }}</flux:text>
            </div>

            <flux:timeline class="mt-5">
                @foreach ($release['items'] as $item)
                    <flux:timeline.item wire:key="entry-{{ $release['date'] }}-{{ $loop->index }}">
                        <flux:timeline.indicator />
                        <flux:timeline.content>
                            <flux:badge size="sm"
                                :color="match ($item['type']) {
                                    'new' => 'teal',
                                    'improved' => 'blue',
                                    default => 'amber',
                                }">
                                {{ match ($item['type']) {
                                    'new' => __('New'),
                                    'improved' => __('Improved'),
                                    default => __('Fixed'),
                                } }}
                            </flux:badge>
                            <flux:heading class="mt-2 leading-snug" size="sm">{{ $item['title'] }}</flux:heading>
                            <flux:text class="mt-1 text-sm leading-relaxed">{{ $item['body'] }}</flux:text>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </div>
    @endforeach

    <flux:text class="text-center text-xs">
        {{ __('Have an idea or spotted a problem? Ask Mahafeth AI — we read along.') }}</flux:text>
</div>
