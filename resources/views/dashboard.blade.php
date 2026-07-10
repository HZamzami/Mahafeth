<x-layouts.app>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        {{-- First-run guided checklist; hides itself once a snapshot exists --}}
        <livewire:dashboard.onboarding />

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

                {{-- Shariah Compliance --}}
                <livewire:dashboard.shariah-compliance />
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

            {{-- Right rail; the AI experience lives on the Advisor tab --}}
            <div class="flex flex-col gap-4 lg:col-span-4">
                {{-- Goal Forecast --}}
                <livewire:dashboard.goal-progress />

                {{-- Market Context --}}
                <livewire:dashboard.news-feed />

                {{-- Company Disclosures --}}
                <livewire:dashboard.company-filings />
            </div>
        </div>
    </div>
</x-layouts.app>
