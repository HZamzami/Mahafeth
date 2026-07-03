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
                <div
                    class="flex grow flex-col rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ __('Asset Allocation') }}</flux:heading>
                    <div class="relative flex grow items-center justify-center py-6">
                        <svg class="aspect-square w-full max-w-64 -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                                class="stroke-blue-500 dark:stroke-blue-400" stroke-dasharray="138.23 113.1"
                                stroke-dashoffset="0" />
                            <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                                class="stroke-emerald-500 dark:stroke-emerald-400" stroke-dasharray="62.83 188.5"
                                stroke-dashoffset="-138.23" />
                            <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                                class="stroke-amber-500 dark:stroke-amber-400" stroke-dasharray="37.7 213.63"
                                stroke-dashoffset="-201.06" />
                            <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                                class="stroke-neutral-300 dark:stroke-zinc-700" stroke-dasharray="12.57 238.76"
                                stroke-dashoffset="-238.76" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <flux:heading size="lg" dir="ltr">$1.4M</flux:heading>
                            <flux:text class="text-xs">{{ __('Total') }}</flux:text>
                        </div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-blue-500 dark:bg-blue-400"></span>
                                <flux:text class="text-sm">{{ __('Equities') }}</flux:text>
                            </div>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">55%</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                                <flux:text class="text-sm">{{ __('Crypto') }}</flux:text>
                            </div>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">25%</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-amber-500 dark:bg-amber-400"></span>
                                <flux:text class="text-sm">{{ __('Fixed Income') }}</flux:text>
                            </div>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">15%</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-neutral-300 dark:bg-zinc-700"></span>
                                <flux:text class="text-sm">{{ __('Cash') }}</flux:text>
                            </div>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">5%</flux:text>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Center column --}}
            <div class="flex flex-col gap-4 lg:col-span-5">
                {{-- Portfolio Health Score --}}
                <div
                    class="flex grow flex-col items-center rounded-xl border border-neutral-200 bg-white p-6 text-center dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ __('Portfolio Health Score') }}</flux:heading>
                    <div class="relative my-8 flex grow items-center justify-center">
                        <svg class="size-56 -rotate-90" viewBox="0 0 224 224">
                            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16"
                                stroke-linecap="round" class="stroke-neutral-100 dark:stroke-zinc-800" />
                            <circle cx="112" cy="112" r="100" fill="transparent" stroke-width="16"
                                stroke-linecap="round" stroke="url(#healthGradient)" stroke-dasharray="628"
                                stroke-dashoffset="138" class="drop-shadow-[0_0_8px_rgba(59,130,246,0.4)]" />
                            <defs>
                                <linearGradient id="healthGradient" x1="0%" y1="0%" x2="100%"
                                    y2="100%">
                                    <stop offset="0%" stop-color="#93c5fd" />
                                    <stop offset="100%" stop-color="#3b82f6" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-6xl font-bold text-blue-600 dark:text-blue-300">78</span>
                            <flux:text class="text-sm uppercase tracking-widest">{{ __('Strong') }}</flux:text>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-3 gap-4 border-t border-neutral-200 pt-6 dark:border-neutral-700">
                        <div class="text-center">
                            <flux:text class="mb-1 text-xs">{{ __('Diversification') }}</flux:text>
                            <flux:heading class="!text-emerald-600 dark:!text-emerald-400" dir="ltr">85/100</flux:heading>
                        </div>
                        <div class="border-x border-neutral-200 text-center dark:border-neutral-700">
                            <flux:text class="mb-1 text-xs">{{ __('Concentration') }}</flux:text>
                            <flux:heading class="!text-amber-600 dark:!text-amber-400" dir="ltr">55/100</flux:heading>
                        </div>
                        <div class="text-center">
                            <flux:text class="mb-1 text-xs">{{ __('Risk Alignment') }}</flux:text>
                            <flux:heading class="!text-blue-600 dark:!text-blue-400" dir="ltr">90/100</flux:heading>
                        </div>
                    </div>
                </div>

                {{-- Total Return --}}
                <div
                    class="relative shrink-0 overflow-hidden rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <flux:heading class="mb-4" size="lg">{{ __('Total Return') }}</flux:heading>
                    <flux:chart class="aspect-3/1 relative" dir="ltr"
                        :value="[
                            ['date' => 'Sep 01', 'return' => 1.2],
                            ['date' => 'Sep 05', 'return' => 3.8],
                            ['date' => 'Sep 10', 'return' => 6.4],
                            ['date' => 'Sep 15', 'return' => 5.9],
                            ['date' => 'Sep 20', 'return' => 7.5],
                            ['date' => 'Sep 25', 'return' => 10.8],
                            ['date' => 'Sep 30', 'return' => 9.6],
                            ['date' => 'Oct 07', 'return' => 11.4],
                            ['date' => 'Oct 14', 'return' => 13.2],
                        ]">
                        <flux:chart.svg>
                            <flux:chart.line class="text-blue-500 dark:text-blue-400" curve="smooth"
                                field="return" />
                            <flux:chart.area class="text-blue-500/10 dark:text-blue-400/10" curve="smooth"
                                field="return" />
                            <flux:chart.axis axis="x" field="date">
                                <flux:chart.axis.tick />
                            </flux:chart.axis>
                            <flux:chart.axis axis="y">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>
                        </flux:chart.svg>
                    </flux:chart>
                </div>
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
