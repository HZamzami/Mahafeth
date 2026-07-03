<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        @if (auth()->user()->riskProfile === null)
            <flux:callout color="blue" icon="clipboard-document-check">
                <flux:callout.heading>{{ __('Complete your investor profile') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Answer five quick questions so Mahafeth can score how well your portfolio fits your goals and risk tolerance.') }}
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button :href="route('investor-profile')" wire:navigate variant="primary" size="sm">
                        {{ __('Start') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        {{-- Key metrics row --}}
        <livewire:dashboard.stat-cards />

        {{-- Main dashboard grid --}}
        <div class="grid flex-1 items-stretch gap-4 lg:grid-cols-12">
            {{-- Left rail --}}
            <div class="flex flex-col gap-4 lg:col-span-3">
                {{-- Open Banking --}}
                <livewire:dashboard.open-banking-panel />

                {{-- Asset Allocation --}}
                <livewire:dashboard.asset-allocation />
            </div>

            {{-- Center column --}}
            <div class="flex flex-col gap-4 lg:col-span-5">
                {{-- Portfolio Health Score --}}
                <livewire:dashboard.health-score />

                {{-- Health Trend --}}
                <livewire:dashboard.health-trend />

                {{-- Total Return --}}
                <livewire:dashboard.performance-chart />
            </div>

            {{-- Right rail --}}
            <div class="flex flex-col gap-4 lg:col-span-4">
                {{-- Mahafeth AI --}}
                <livewire:dashboard.ai-summary />

                {{-- Market Context --}}
                <div
                    class="shrink-0 space-y-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400"
                        size="sm">
                        {{ __('Market Context') }}
                    </flux:heading>

                    <a class="flex cursor-pointer gap-4 rounded-lg border border-neutral-200/60 bg-neutral-50 p-4 transition-colors hover:bg-neutral-100 dark:border-neutral-700/60 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
                        href="#">
                        <div
                            class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-200 dark:bg-zinc-800">
                            <flux:icon.newspaper class="size-6 text-neutral-400 dark:text-zinc-500" />
                        </div>
                        <div>
                            <flux:heading class="leading-snug" size="sm">{{ __('Tech Overlap: Why dual-broker setups are risky right now...') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __(':minutes min read', ['minutes' => 4]) }} &bull; {{ __('Alpha Insights') }}</flux:text>
                        </div>
                    </a>

                    <a class="flex cursor-pointer gap-4 rounded-lg border border-neutral-200/60 bg-neutral-50 p-4 transition-colors hover:bg-neutral-100 dark:border-neutral-700/60 dark:bg-zinc-800/50 dark:hover:bg-zinc-800"
                        href="#">
                        <div
                            class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-200 dark:bg-zinc-800">
                            <flux:icon.chart-bar class="size-6 text-neutral-400 dark:text-zinc-500" />
                        </div>
                        <div>
                            <flux:heading class="leading-snug" size="sm">{{ __('Fed Policy Update: How it impacts your crypto-equity ratio...') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __(':minutes min read', ['minutes' => 7]) }} &bull; {{ __('Market Pulse') }}</flux:text>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
