<?php

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Models\AiInsight;
use App\Models\Holding;
use App\Services\Analytics\PortfolioDataAssembler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $user = Auth::user();
        $snapshot = $user->latestSnapshot();

        return [
            'snapshot' => $snapshot,
            'metrics' => $snapshot?->metrics ?? [],
            'components' => $snapshot?->component_scores,
            'profile' => $user->riskProfile,
            'holdings' => $this->holdingRows(),
            'insight' => $snapshot === null ? null : AiInsight::query()
                ->where('portfolio_snapshot_id', $snapshot->id)
                ->where('locale', app()->getLocale())
                ->first(),
        ];
    }

    /**
     * Per-symbol holdings with cost basis and unrealized P&L, all in base
     * currency, sorted by value.
     *
     * @return array{rows: list<array>, totalValue: float, totalCost: float}
     */
    private function holdingRows(): array
    {
        $user = Auth::user();

        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');

        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears));
        $fxRates = config('mahafeth.fx_rates');

        $costs = [];
        $names = [];

        $dbHoldings = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        foreach ($dbHoldings as $holding) {
            $symbol = $holding->asset->symbol;
            $rate = $fxRates[$holding->asset->currency] ?? 1.0;
            $costs[$symbol] = ($costs[$symbol] ?? 0.0) + $holding->quantity * $holding->avg_cost * $rate;
            $names[$symbol] = $holding->asset->localizedName();
        }

        $rows = [];

        foreach ($data['quantities'] as $symbol => $quantity) {
            $series = $data['priceSeries'][$symbol] ?? [];

            if ($series === []) {
                continue;
            }

            $value = $quantity * end($series);
            $cost = $costs[$symbol] ?? 0.0;

            $rows[] = [
                'symbol' => $symbol,
                'name' => $names[$symbol] ?? $symbol,
                'quantity' => $quantity,
                'value' => $value,
                'cost' => $cost,
                'pl' => $value - $cost,
                'plPct' => $cost > 0 ? ($value - $cost) / $cost : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return [
            'rows' => $rows,
            'totalValue' => array_sum(array_column($rows, 'value')),
            'totalCost' => array_sum(array_column($rows, 'cost')),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    @if ($snapshot === null)
        <div
            class="flex flex-col items-center justify-center gap-4 rounded-xl border border-neutral-200 bg-white p-16 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:text>{{ __('Connect your accounts and run an analysis to build your report.') }}</flux:text>
            <flux:button variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @else
        @php($totalPl = $holdings['totalValue'] - $holdings['totalCost'])
        @php($fmt = fn ($value, $decimals = 1) => $value === null ? '—' : Number::percentage($value * 100, $decimals))

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
                        ${{ Number::abbreviate($snapshot->total_value, 1) }}</flux:heading>
                    @if ($holdings['totalCost'] > 0)
                        <flux:text
                            class="text-xs {{ $totalPl >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            dir="ltr">
                            {{ __('Unrealized P/L') }}: {{ $totalPl >= 0 ? '+' : '−' }}${{ Number::abbreviate(abs($totalPl), 1) }}
                        </flux:text>
                    @endif
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
                        [__('Annualized Return'), $fmt($metrics['expected_return'] ?? null)],
                        [__('Annualized Volatility'), $fmt($metrics['volatility'] ?? null)],
                        [__('Beta'), isset($metrics['beta']) ? number_format($metrics['beta'], 2) : '—'],
                        [__('Sharpe Ratio'), isset($metrics['sharpe']) ? number_format($metrics['sharpe'], 2) : '—'],
                        [__('Sortino Ratio'), isset($metrics['sortino']) ? number_format($metrics['sortino'], 2) : '—'],
                        [__('Value at Risk (95%)'), $fmt($metrics['var_95'] ?? null)],
                        [__('Expected Shortfall (CVaR)'), $fmt($metrics['cvar_95'] ?? null)],
                        [__('Max Drawdown'), $fmt($metrics['max_drawdown'] ?? null)],
                        [__('Effective Holdings'), isset($metrics['effective_holdings']) ? number_format($metrics['effective_holdings'], 1) : '—'],
                        [__('Average Correlation'), isset($metrics['average_correlation']) ? number_format($metrics['average_correlation'], 2) : '—'],
                        [__('Hidden Factor (PCA)'), $fmt($metrics['pca_first_factor_share'] ?? null, 0)],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between gap-2">
                            <flux:text class="text-sm">{{ $label }}</flux:text>
                            <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                {{ $value }}</flux:text>
                        </div>
                    @endforeach
                </div>
                <flux:text class="mt-3 text-xs">
                    {{ __('Returns are trailing and annualized. VaR and CVaR are the worst expected annual loss at 95% confidence.') }}
                </flux:text>
            </div>

            {{-- Holdings & P/L --}}
            @if ($holdings['rows'] !== [])
                <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                    <flux:heading size="lg">{{ __('Holdings') }}</flux:heading>
                    <table class="mt-4 w-full text-sm">
                        <thead>
                            <tr class="text-start text-xs uppercase tracking-wide text-neutral-400">
                                <th class="pb-2 text-start font-medium">{{ __('Asset') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Quantity') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Value') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Cost Basis') }}</th>
                                <th class="pb-2 text-end font-medium">{{ __('Unrealized P/L') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($holdings['rows'] as $row)
                                <tr class="border-t border-neutral-100 dark:border-zinc-800">
                                    <td class="py-1.5">
                                        <span class="font-medium text-zinc-800 dark:text-white">{{ $row['symbol'] }}</span>
                                        <span class="text-neutral-400"> · {{ $row['name'] }}</span>
                                    </td>
                                    <td class="py-1.5 text-end tabular-nums" dir="ltr">
                                        {{ number_format($row['quantity'], 2) }}</td>
                                    <td class="py-1.5 text-end tabular-nums" dir="ltr">
                                        ${{ number_format($row['value'], 0) }}</td>
                                    <td class="py-1.5 text-end tabular-nums" dir="ltr">
                                        ${{ number_format($row['cost'], 0) }}</td>
                                    <td class="py-1.5 text-end tabular-nums {{ $row['pl'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}"
                                        dir="ltr">
                                        {{ $row['pl'] >= 0 ? '+' : '−' }}${{ number_format(abs($row['pl']), 0) }}
                                        ({{ number_format($row['plPct'] * 100, 1) }}%)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

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
                                    {{ $fmt($weight) }}</flux:text>
                            </div>
                        @endforeach
                    </div>

                    <flux:heading class="mt-6" size="lg">{{ __('Currency Exposure') }}</flux:heading>
                    <div class="mt-3 space-y-1.5">
                        @foreach ($metrics['allocations']['currency'] ?? [] as $currency => $weight)
                            <div class="flex items-center justify-between">
                                <flux:text class="text-sm">{{ $currency }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ $fmt($weight) }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <flux:heading size="lg">{{ __('Sector Allocation') }}</flux:heading>
                    <div class="mt-3 space-y-1.5">
                        @foreach (array_slice($metrics['allocations']['sector'] ?? [], 0, 5, true) as $sector => $weight)
                            <div class="flex items-center justify-between">
                                <flux:text class="text-sm">{{ __($sector) }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ $fmt($weight) }}</flux:text>
                            </div>
                        @endforeach
                    </div>

                    <flux:heading class="mt-6" size="lg">{{ __('Country Exposure') }}</flux:heading>
                    <div class="mt-3 space-y-1.5">
                        @foreach ($metrics['allocations']['country'] ?? [] as $country => $weight)
                            <div class="flex items-center justify-between">
                                <flux:text class="text-sm">{{ $country }}</flux:text>
                                <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ $fmt($weight) }}</flux:text>
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
