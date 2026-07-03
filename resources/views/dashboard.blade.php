<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
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

                {{-- Total Return --}}
                <livewire:dashboard.performance-chart />
            </div>

            {{-- Right rail --}}
            <div class="flex flex-col gap-4 lg:col-span-4">
                {{-- Mahafeth AI --}}
                <div
                    class="flex grow flex-col rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <div class="mb-6 flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-500/20">
                            <flux:icon.sparkles class="size-5 text-blue-600 dark:text-blue-300" />
                        </div>
                        <flux:heading class="!text-blue-600 dark:!text-blue-300" size="lg">{{ __('Mahafeth AI') }}
                        </flux:heading>
                    </div>

                    <flux:callout color="amber" icon="light-bulb">
                        <flux:callout.heading>{{ __('Executive Summary') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('High correlation detected between Robinhood and Fidelity tech holdings. Significant') }}
                            <strong>{{ __('cross-app tech overlap risk') }}</strong> {{ __('identified (Microsoft & Nvidia).') }}
                        </flux:callout.text>
                    </flux:callout>

                    <div class="flex grow flex-col">
                        <flux:text class="mb-4 mt-6 text-xs">{{ __('Rebalancing Impact Analysis') }}</flux:text>
                        <div class="flex grow items-end gap-6 px-4">
                            <div class="flex flex-1 flex-col items-center">
                                <div class="relative h-24 w-full rounded-t-sm bg-neutral-100 dark:bg-zinc-800">
                                    <div
                                        class="absolute bottom-0 h-20 w-full rounded-t-sm bg-neutral-400/50 dark:bg-zinc-500/50">
                                    </div>
                                </div>
                                <flux:text class="mt-2 text-xs">{{ __('Current') }}</flux:text>
                            </div>
                            <div class="flex flex-1 flex-col items-center">
                                <div class="relative h-24 w-full rounded-t-sm bg-neutral-100 dark:bg-zinc-800">
                                    <div
                                        class="absolute bottom-0 h-24 w-full rounded-t-sm bg-emerald-500 dark:bg-emerald-400">
                                    </div>
                                </div>
                                <flux:text class="mt-2 text-xs">{{ __('Optimized') }}</flux:text>
                            </div>
                        </div>
                    </div>

                    <flux:button class="mt-8 w-full" icon="scale" variant="primary">{{ __('Approve Rebalance') }}</flux:button>
                </div>

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
