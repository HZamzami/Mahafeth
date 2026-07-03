<?php

use App\Enums\AssetClass;
use App\Models\AiInsight;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $snapshot = $user->latestSnapshot();

        return [
            'snapshot' => $snapshot,
            'metrics' => $snapshot?->metrics,
            'components' => $snapshot?->component_scores,
            'profile' => $user->riskProfile,
            'insight' => $snapshot === null ? null : AiInsight::query()
                ->where('portfolio_snapshot_id', $snapshot->id)
                ->where('locale', app()->getLocale())
                ->first(),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    @if ($snapshot === null)
        <div
            class="flex items-center justify-center rounded-xl border border-neutral-200 bg-white p-16 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:text>{{ __('Run an analysis first to generate AI insights.') }}</flux:text>
        </div>
    @else
        <div class="flex items-start justify-between print:hidden">
            <div>
                <flux:heading size="xl">{{ __('Portfolio Report') }}</flux:heading>
                <flux:text class="mt-1">{{ __('A snapshot of your unified portfolio, ready to print or save as PDF.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="printer" onclick="window.print()">
                {{ __('Print / Save as PDF') }}</flux:button>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-8 print:border-0 print:p-0 dark:border-neutral-700 dark:bg-zinc-900">
            {{-- Header --}}
            <div class="flex items-start justify-between border-b border-neutral-200 pb-6 dark:border-neutral-700">
                <div>
                    <flux:heading size="xl">{{ __('Portfolio Report') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">{{ __('Prepared for :name', ['name' => auth()->user()->name]) }}
                        &bull; {{ __('Generated on :date', ['date' => $snapshot->as_of->isoFormat('LL')]) }}
                    </flux:text>
                </div>
                <div class="text-end">
                    <flux:text class="text-xs uppercase tracking-widest">{{ __('Total Portfolio Value') }}</flux:text>
                    <flux:heading size="xl" dir="ltr">
                        ${{ \Illuminate\Support\Number::abbreviate($snapshot->total_value, 1) }}</flux:heading>
                </div>
            </div>

            {{-- Health --}}
            <div class="grid gap-6 border-b border-neutral-200 py-6 sm:grid-cols-2 dark:border-neutral-700">
                <div>
                    <flux:text class="mb-1 text-xs uppercase tracking-widest">{{ __('Portfolio Health Score') }}
                    </flux:text>
                    <flux:heading class="!text-blue-600 dark:!text-blue-400" size="xl" dir="ltr">
                        {{ $snapshot->health_score !== null ? $snapshot->health_score.'/100' : '—' }}</flux:heading>
                    @if ($profile !== null)
                        <flux:text class="mt-2 text-sm">
                            {{ __('Investor Profile') }}: {{ $profile->risk_tolerance->label() }} &bull;
                            {{ $profile->time_horizon->label() }}</flux:text>
                    @endif
                </div>
                @if ($components !== null)
                    <div class="grid grid-cols-2 gap-x-6 gap-y-2">
                        @foreach ([
                            'diversification' => __('Diversification'),
                            'correlation' => __('Correlation'),
                            'risk_alignment' => __('Risk Alignment'),
                            'performance' => __('Performance'),
                            'drawdown' => __('Drawdown'),
                            'concentration' => __('Concentration'),
                        ] as $key => $label)
                            <div class="flex items-center justify-between gap-2">
                                <flux:text class="text-sm">{{ $label }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ $components[$key] ?? '—' }}/100</flux:text>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Key metrics --}}
            <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                <flux:heading size="lg">{{ __('Key Metrics') }}</flux:heading>
                <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                    @foreach ([
                        [__('Expected Annualized Return'), \Illuminate\Support\Number::percentage($metrics['expected_return'] * 100, 1)],
                        [__('Annualized Volatility'), \Illuminate\Support\Number::percentage($metrics['volatility'] * 100, 1)],
                        [__('Beta'), number_format($metrics['beta'], 2)],
                        [__('Sharpe Ratio'), number_format($metrics['sharpe'], 2)],
                        [__('Value at Risk (95%)'), \Illuminate\Support\Number::percentage($metrics['var_95'] * 100, 1)],
                        [__('Max Drawdown'), \Illuminate\Support\Number::percentage($metrics['max_drawdown'] * 100, 1)],
                        [__('Effective Holdings'), number_format($metrics['effective_holdings'], 1)],
                        [__('Average Correlation'), number_format($metrics['average_correlation'], 2)],
                        [__('Hidden Factor (PCA)'), \Illuminate\Support\Number::percentage(($metrics['pca_first_factor_share'] ?? 0) * 100)],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between gap-2">
                            <flux:text class="text-sm">{{ $label }}</flux:text>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                {{ $value }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Allocations --}}
            <div class="grid gap-8 border-b border-neutral-200 py-6 sm:grid-cols-2 dark:border-neutral-700">
                <div>
                    <flux:heading size="lg">{{ __('Asset Allocation') }}</flux:heading>
                    <div class="mt-3 space-y-1.5">
                        @foreach ($metrics['allocations']['asset_class'] ?? [] as $class => $weight)
                            <div class="flex items-center justify-between">
                                <flux:text class="text-sm">
                                    {{ AssetClass::tryFrom($class)?->label() ?? $class }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ \Illuminate\Support\Number::percentage($weight * 100, 1) }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <flux:heading size="lg">{{ __('Largest Sector') }}</flux:heading>
                    <div class="mt-3 space-y-1.5">
                        @foreach (array_slice($metrics['allocations']['sector'] ?? [], 0, 5, true) as $sector => $weight)
                            <div class="flex items-center justify-between">
                                <flux:text class="text-sm">{{ __($sector) }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ \Illuminate\Support\Number::percentage($weight * 100, 1) }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- AI insight --}}
            @if ($insight !== null)
                <div class="py-6">
                    <flux:heading size="lg">{{ __('Mahafeth AI') }} — {{ __('Executive Summary') }}</flux:heading>
                    <flux:text class="mt-3 text-sm leading-relaxed">{{ $insight->summary }}</flux:text>

                    <flux:heading class="mt-6" size="lg">{{ __('Action Plan') }}</flux:heading>
                    <div class="mt-3 space-y-3">
                        @foreach ($insight->recommendations as $index => $recommendation)
                            <div>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                                    {{ $index + 1 }}. {{ $recommendation['title'] }}</flux:text>
                                <flux:text class="mt-0.5 text-sm">{{ $recommendation['body'] }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <flux:text class="border-t border-neutral-200 pt-4 text-xs dark:border-neutral-700">
                {{ __('This report is educational analysis generated by Mahafeth and is not licensed financial advice.') }}
            </flux:text>
        </div>
    @endif
</div>
