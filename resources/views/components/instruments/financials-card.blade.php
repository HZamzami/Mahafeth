{{-- CNN-Markets-style quarterly results: four headline numbers with a
     change arrow against the prior quarter, then a grouped bar chart of
     revenue vs net income across the trailing four quarters. --}}
@props(['headline', 'quarters', 'currencySymbol'])

@php
use Illuminate\Support\Number;

$chartQuarters = collect($quarters)
    ->filter(fn (array $quarter): bool => $quarter['revenue'] !== null || $quarter['earnings'] !== null)
    ->values()
    ->all();

$stats = [
    ['label' => __('Total Revenue'), 'value' => $headline['revenue'] !== null ? $currencySymbol.' '.Number::localizedAbbreviate($headline['revenue']) : null, 'change' => $headline['revenueChange']],
    ['label' => __('Net Income'), 'value' => $headline['netIncome'] !== null ? $currencySymbol.' '.Number::localizedAbbreviate($headline['netIncome']) : null, 'change' => $headline['netIncomeChange']],
    ['label' => __('Earnings per Share'), 'value' => $headline['eps'] !== null ? number_format($headline['eps'], 2) : null, 'change' => $headline['epsChange']],
    ['label' => __('Net Profit Margin'), 'value' => $headline['netMargin'] !== null ? Number::percentage($headline['netMargin'] * 100, 1) : null, 'change' => null],
];
@endphp

<div class="card p-5">
    <div class="flex items-center justify-between">
        <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
            {{ __('Financials') }}</flux:heading>
        <flux:text class="text-xs !text-neutral-400">{{ __('Data by Yahoo Finance') }}</flux:text>
    </div>

    @if ($headline['quarterLabel'] !== null)
        <flux:text class="mt-1 text-xs">
            {{ __('Latest reported quarter: :quarter', ['quarter' => $headline['quarterLabel']]) }}</flux:text>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
        @foreach ($stats as $stat)
            <div>
                <flux:text class="text-xs">{{ $stat['label'] }}</flux:text>
                <flux:heading dir="ltr">{{ $stat['value'] ?? '—' }}</flux:heading>
                @if ($stat['change'] !== null)
                    <p class="mt-0.5 flex items-center gap-1 text-xs font-medium {{ $stat['change'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                        @if ($stat['change'] >= 0)
                            <flux:icon.arrow-trending-up class="size-3.5 shrink-0" />
                        @else
                            <flux:icon.arrow-trending-down class="size-3.5 shrink-0" />
                        @endif
                        <span dir="ltr">{{ ($stat['change'] >= 0 ? '+' : '−').number_format(abs($stat['change']) * 100, 1) }}%</span>
                        <span class="font-normal text-neutral-400">{{ __('vs prior quarter') }}</span>
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    @if (count($chartQuarters) > 1)
        <div class="mt-6">
            <flux:text class="text-xs">{{ __('Revenue & net income by quarter') }}</flux:text>

            <flux:chart class="relative mt-2 aspect-5/2 overflow-hidden" dir="ltr" :value="$chartQuarters">
                <flux:chart.svg>
                    <flux:chart.group>
                        <flux:chart.bar class="text-teal-600 dark:text-teal-400" field="revenue" radius="3" />
                        <flux:chart.bar class="text-amber-500 dark:text-amber-400" field="earnings" radius="3" />
                    </flux:chart.group>

                    <flux:chart.axis axis="x" field="label">
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y" :format="['notation' => 'compact']">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                </flux:chart.svg>

                <flux:chart.tooltip class="max-w-44">
                    <flux:chart.tooltip.heading field="label" />
                    <flux:chart.tooltip.value :label="__('Total Revenue')" field="revenue"
                        :format="['notation' => 'compact']" />
                    <flux:chart.tooltip.value :label="__('Net Income')" field="earnings"
                        :format="['notation' => 'compact']" />
                </flux:chart.tooltip>
            </flux:chart>

            <div class="mt-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
                <span class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-teal-600 dark:bg-teal-400"></span>
                    <flux:text class="text-xs">{{ __('Total Revenue') }}</flux:text>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-amber-500 dark:bg-amber-400"></span>
                    <flux:text class="text-xs">{{ __('Net Income') }}</flux:text>
                </span>
            </div>
        </div>
    @endif
</div>
