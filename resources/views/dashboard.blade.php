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

        {{-- Threshold alerts --}}
        <livewire:dashboard.alerts />

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
                <livewire:dashboard.news-feed />
            </div>
        </div>
    </div>
</x-layouts.app>
