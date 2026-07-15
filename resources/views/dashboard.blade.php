<x-layouts.app>
    <div class="stagger-children relative flex h-full w-full flex-1 flex-col gap-4 rounded-xl"
        x-data="{
            hideAmounts: localStorage.getItem('hideAmounts') === '1',
            toggle() {
                this.hideAmounts = ! this.hideAmounts;
                localStorage.setItem('hideAmounts', this.hideAmounts ? '1' : '0');
            },
        }"
        x-bind:class="hideAmounts && 'amounts-hidden'">
    @include('partials.page-glow')
        {{-- Greeting + total value hero --}}
        <livewire:dashboard.portfolio-hero />

        {{-- First-run guided checklist; hides itself once a snapshot exists --}}
        <livewire:dashboard.onboarding />

        @include('partials.passkey-nudge')

        {{-- Threshold alerts --}}
        <livewire:dashboard.alerts />

        {{-- Key metrics row --}}
        <livewire:dashboard.stat-cards />

        {{-- What moved the portfolio since the previous snapshot --}}
        <livewire:dashboard.daily-move />

        {{-- Main dashboard grid; on phones the health column leads --}}
        <div class="grid flex-1 items-stretch gap-4 lg:grid-cols-12">
            {{-- Left rail --}}
            <div class="stagger-children flex flex-col gap-4 max-lg:order-2 lg:col-span-3">
                {{-- Open Banking --}}
                <livewire:dashboard.open-banking-panel lazy />

                {{-- Asset Allocation --}}
                <livewire:dashboard.asset-allocation lazy />

                {{-- Shariah Compliance --}}
                <livewire:dashboard.shariah-compliance lazy />
            </div>

            {{-- Center column --}}
            <div class="stagger-children flex flex-col gap-4 max-lg:order-1 lg:col-span-5">
                {{-- Portfolio Health Score --}}
                <livewire:dashboard.health-score />

                {{-- Health Trend --}}
                <livewire:dashboard.health-trend />

                {{-- Total Return --}}
                <livewire:dashboard.performance-chart lazy />
            </div>

            {{-- Right rail; the AI experience lives on the Advisor tab --}}
            <div class="stagger-children flex flex-col gap-4 max-lg:order-3 lg:col-span-4">
                {{-- Goal Forecast --}}
                <livewire:dashboard.goal-progress lazy />

                {{-- Market Context --}}
                <livewire:dashboard.news-feed lazy />

                {{-- Company Disclosures --}}
                <livewire:dashboard.company-filings lazy />
            </div>
        </div>
    </div>
</x-layouts.app>
