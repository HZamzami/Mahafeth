<?php

use App\Enums\ActivityCategory;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    private const MAX_PER_TAB = 50;

    /**
     * One capped feed per category tab, newest first.
     */
    public function with(): array
    {
        $feeds = [];

        foreach (ActivityCategory::cases() as $category) {
            $feeds[$category->value] = ActivityEvent::query()
                ->whereBelongsTo(Auth::user())
                ->whereIn('type', $category->types())
                ->latest('id')
                ->limit(self::MAX_PER_TAB)
                ->get();
        }

        return ['feeds' => $feeds];
    }
}; ?>

<div class="stagger-children relative mx-auto flex w-full max-w-5xl flex-col gap-6">
    @include('partials.page-glow')
    <div>
        <flux:heading size="xl">{{ __('Activity') }}</flux:heading>
        <flux:text class="mt-1 text-balance">
            {{ __('Everything Mahafeth noticed, changed, and secured — in one trail.') }}
        </flux:text>
    </div>

    <flux:tab.group class="flex flex-col gap-6">
        <x-scroll-hint>
            <flux:tabs scrollable scrollable:scrollbar="hide">
                @foreach (App\Enums\ActivityCategory::cases() as $category)
                    <flux:tab :name="$category->value" :selected="$loop->first" :icon="$category->icon()">
                        {{ $category->label() }}</flux:tab>
                @endforeach
            </flux:tabs>
        </x-scroll-hint>

        @foreach (App\Enums\ActivityCategory::cases() as $category)
            <flux:tab.panel :name="$category->value" :selected="$loop->first" class="flex flex-col gap-4">
                <div class="card p-5">
                    @forelse ($feeds[$category->value] as $event)
                        <div wire:key="activity-{{ $event->id }}"
                            class="flex items-start gap-3 border-t border-neutral-100 py-3 first:border-t-0 first:pt-0 last:pb-0 dark:border-zinc-800">
                            <div @class([
                                'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                                'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => $event->type->color() === 'red',
                                'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' => $event->type->color() === 'amber',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' => $event->type->color() === 'emerald',
                                'bg-neutral-100 text-neutral-600 dark:bg-zinc-800 dark:text-neutral-300' => $event->type->color() === 'zinc',
                            ])>
                                <flux:icon :icon="$event->type->icon()" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <flux:text class="text-sm !text-zinc-800 dark:!text-zinc-100">
                                    {{ $event->type->label($event->params ?? []) }}</flux:text>
                                <flux:text class="mt-0.5 text-xs">
                                    {{ $event->created_at->diffForHumans() }}</flux:text>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 py-10 text-center">
                            <flux:icon :icon="$category->icon()" class="size-6 text-neutral-400" />
                            <flux:text class="max-w-72 text-sm">{{ $category->emptyState() }}</flux:text>
                        </div>
                    @endforelse
                </div>
            </flux:tab.panel>
        @endforeach
    </flux:tab.group>
</div>
