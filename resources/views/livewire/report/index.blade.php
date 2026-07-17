<?php

use App\Enums\AssetClass;
use App\Enums\ConnectionStatus;
use App\Enums\ShariahStatus;
use App\Models\AiInsight;
use App\Models\Holding;
use App\Services\Analytics\GoalForecaster;
use App\Services\Analytics\HoldingsSummarizer;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\RealizedGainCalculator;
use App\Services\Analytics\RebalancePlanner;
use App\Services\Fx\FxRateService;
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
            'goalForecasts' => $this->goalForecasts($snapshot),
            'rebalanceOrders' => $this->rebalanceOrders($snapshot),
            'metrics' => $snapshot?->metrics ?? [],
            'components' => $snapshot?->component_scores,
            'profile' => $user->riskProfile,
            'holdings' => app(HoldingsSummarizer::class)->rows($user),
            'realizedPl' => app(RealizedGainCalculator::class)->forUser($user),
            'insight' => $snapshot === null ? null : AiInsight::query()
                ->where('portfolio_snapshot_id', $snapshot->id)
                ->where('locale', app()->getLocale())
                ->first(),
        ];
    }

    /**
     * Orders that would move the portfolio to the optimal allocation.
     *
     * @return list<array{symbol: string, name: string, side: string, quantity: float, value: float, current_weight: float, target_weight: float}>
     */
    private function rebalanceOrders($snapshot): array
    {
        $metrics = $snapshot?->metrics ?? [];
        $targetWeights = $metrics['frontier']['recommended']['weights'] ?? $metrics['frontier']['tangency']['weights'] ?? [];

        if ($snapshot === null || $targetWeights === [] || ! isset($metrics['weights'])) {
            return [];
        }

        $user = Auth::user();
        $windowYears = $user->riskProfile?->time_horizon->analysisWindowYears()
            ?? (int) config('mahafeth.analysis_window_years');
        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subYears($windowYears));

        return app(RebalancePlanner::class)->plan(
            currentWeights: $metrics['weights'],
            targetWeights: $targetWeights,
            totalValue: (float) $snapshot->total_value,
            quantities: $data['quantities'],
            assets: $data['assets'],
            shariahRequired: (bool) ($user->riskProfile?->constraints['shariah_required'] ?? false),
        );
    }

    /**
     * Monte Carlo success odds per goal at the current and optimal mixes.
     *
     * @return list<array{name: string, target: float, months: int, probability: float, probabilityOptimal: ?float, median: float}>
     */
    private function goalForecasts($snapshot): array
    {
        $metrics = $snapshot?->metrics ?? [];

        if ($snapshot === null || ! isset($metrics['expected_return'], $metrics['volatility'])) {
            return [];
        }

        $forecaster = app(GoalForecaster::class);
        $tangency = $metrics['frontier']['tangency'] ?? null;
        $rows = [];

        foreach (Auth::user()->goals()->orderBy('target_date')->get() as $goal) {
            $months = $goal->monthsRemaining();

            $current = $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $metrics['expected_return'],
                annualVolatility: (float) $metrics['volatility'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $optimal = $tangency === null ? null : $forecaster->forecast(
                currentValue: (float) $snapshot->total_value,
                annualReturn: (float) $tangency['return'],
                annualVolatility: (float) $tangency['risk'],
                targetAmount: $goal->target_amount,
                months: $months,
                monthlyContribution: $goal->monthly_contribution ?? 0.0,
            );

            $rows[] = [
                'name' => $goal->name,
                'target' => $goal->target_amount,
                'months' => $months,
                'probability' => $current['probability'],
                'probabilityOptimal' => $optimal['probability'] ?? null,
                'median' => $current['final']['p50'],
            ];
        }

        return $rows;
    }

}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    @if ($snapshot === null)
        <div
            class="flex flex-col items-center justify-center gap-4 card p-16">
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
                <flux:text class="mt-1 text-balance">{{ __('A snapshot of your unified portfolio, ready to print or save as PDF.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="printer" onclick="window.print()">
                {{ __('Print / Save as PDF') }}</flux:button>
        </div>

        <div class="card p-8 print:border-0 print:p-0">
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
                        ⃁ {{ Number::localizedAbbreviate($snapshot->total_value, 1) }}</flux:heading>
                    @if ($holdings['totalCost'] > 0)
                        <flux:text
                            class="text-xs {{ $totalPl >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            dir="ltr">
                            {{ __('Unrealized P/L') }}: ⃁ {{ $totalPl >= 0 ? '+' : '−' }}{{ Number::localizedAbbreviate(abs($totalPl), 1) }}
                        </flux:text>
                    @endif
                    @if (round($realizedPl, 2) != 0.0)
                        <flux:text
                            class="text-xs {{ $realizedPl >= 0 ? '!text-emerald-600 dark:!text-emerald-400' : '!text-red-600 dark:!text-red-400' }}"
                            dir="ltr">
                            {{ __('Realized P/L') }}: ⃁ {{ $realizedPl >= 0 ? '+' : '−' }}{{ Number::localizedAbbreviate(abs($realizedPl), 1) }}
                        </flux:text>
                    @endif
                </div>
            </div>

            {{-- Health --}}
            <div class="grid gap-6 border-b border-neutral-200 py-6 sm:grid-cols-2 dark:border-neutral-700">
                <div>
                    <flux:text class="mb-1 text-xs uppercase tracking-widest">{{ __('Portfolio Health Score') }}
                    </flux:text>
                    <flux:heading class="!text-teal-700 dark:!text-teal-300" size="xl" dir="ltr">
                        {{ $snapshot->health_score !== null ? $snapshot->health_score.'/100' : '—' }}</flux:heading>
                    @if ($profile !== null)
                        <flux:text class="mt-2 text-sm">
                            {{ __('Investor Profile') }}: {{ $profile->risk_tolerance->label() }} &bull;
                            {{ $profile->time_horizon->label() }}</flux:text>
                    @endif
                </div>
                @if ($components !== null)
                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach ([
                            'diversification' => __('Diversification'),
                            'correlation' => __('Correlation'),
                            'risk_alignment' => __('Risk Alignment'),
                            'performance' => __('Performance'),
                            'drawdown' => __('Drawdown'),
                            'concentration' => __('Concentration'),
                            'shariah' => __('Shariah Compliance'),
                        ] as $key => $label)
                            @continue($key === 'shariah' && ! isset($components[$key]))
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
                <div class="mt-4 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2 md:grid-cols-3">
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

            {{-- Goals --}}
            @if ($goalForecasts !== [])
                <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                    <flux:heading size="lg">{{ __('Financial Goals') }}</flux:heading>
                    <div class="mt-4 overflow-x-auto scrollbar-thin">
                        <table class="w-full text-sm">
                            <thead>
                                {{-- The label column absorbs the free width so the numeric
                                     columns hug their headers instead of scattering. --}}
                                <tr class="text-xs uppercase tracking-wide text-neutral-400">
                                    <th class="w-full pb-2 text-start font-medium">{{ __('Goal') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Target') }} (⃁)</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Horizon') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Success odds') }}</th>
                                    <th class="hidden whitespace-nowrap pb-2 ps-4 text-end font-medium sm:table-cell sm:ps-8">{{ __('At optimal mix') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($goalForecasts as $row)
                                    <tr class="border-t border-neutral-100 dark:border-zinc-800">
                                        <td class="py-1.5 font-medium text-zinc-800 dark:text-white">{{ $row['name'] }}</td>
                                        {{-- dir=ltr lives on an inner span: on the cell itself it flips
                                             what text-end means and detaches values from their headers in RTL. --}}
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8"><span dir="ltr">{{ number_format($row['target'], 0) }}</span></td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8">{{ __(':months mo', ['months' => $row['months']]) }}</td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8"><span dir="ltr">{{ Number::percentage($row['probability'] * 100, 0) }}</span></td>
                                        <td class="hidden whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:table-cell sm:ps-8">
                                            <span dir="ltr">{{ $row['probabilityOptimal'] !== null ? Number::percentage($row['probabilityOptimal'] * 100, 0) : '—' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Shariah screening --}}
            @if (isset($metrics['shariah']))
                <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                    <flux:heading size="lg">{{ __('Shariah Compliance') }}</flux:heading>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-x-8">
                        @foreach ([
                            [__('Shariah Compliant'), $metrics['shariah']['compliant_weight'], '!text-emerald-600 dark:!text-emerald-400'],
                            [__('Compliance Unknown'), $metrics['shariah']['unknown_weight'], '!text-amber-600 dark:!text-amber-400'],
                            [__('Not Shariah Compliant'), $metrics['shariah']['non_compliant_weight'], '!text-red-600 dark:!text-red-400'],
                        ] as [$label, $weight, $color])
                            <div>
                                <flux:text class="text-sm">{{ $label }}</flux:text>
                                <flux:heading class="{{ $color }}" size="lg" dir="ltr">
                                    {{ Number::percentage($weight * 100, 1) }}</flux:heading>
                            </div>
                        @endforeach
                    </div>
                    @if ($metrics['shariah']['non_compliant_positions'] !== [])
                        <flux:text class="mt-3 text-xs">
                            {{ __('Flagged Positions') }}:
                            {{ collect($metrics['shariah']['non_compliant_positions'])->map(fn (array $position) => $position['name'].' ('.Number::percentage($position['weight'] * 100, 1).')')->join(', ') }}
                        </flux:text>
                    @endif
                    @if (($metrics['shariah']['purification_amount'] ?? 0) > 0)
                        <flux:text class="mt-1 text-xs">
                            {{ __('Stock purification: ⃁ :amount in dividends from non-compliant holdings over the past year.', ['amount' => Number::format($metrics['shariah']['purification_amount'], 2)]) }}
                            <a class="font-medium text-teal-700 hover:underline dark:text-teal-300"
                                href="https://ehsan.sa/stockspurification" target="_blank" rel="noopener">{{ __('Donate via Ehsan') }}</a>
                        </flux:text>
                    @endif
                    @if (($metrics['zakat']['zakat_due'] ?? 0) > 0)
                        <flux:text class="mt-1 text-xs">
                            {{ __('Zakat due: ⃁ :amount on zakatable wealth of ⃁ :wealth.', ['amount' => Number::format($metrics['zakat']['zakat_due'], 2), 'wealth' => Number::format($metrics['zakat']['zakatable_value'], 0)]) }}
                        </flux:text>
                    @endif
                </div>
            @endif

            {{-- Holdings & P/L --}}
            @if ($holdings['rows'] !== [])
                <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                    <flux:heading size="lg">{{ __('Holdings') }}</flux:heading>
                    <div class="mt-4 overflow-x-auto scrollbar-thin">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-start text-xs uppercase tracking-wide text-neutral-400">
                                    <th class="w-full pb-2 text-start font-medium">{{ __('Asset') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Quantity') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Value') }} (⃁)</th>
                                    <th class="hidden whitespace-nowrap pb-2 ps-4 text-end font-medium sm:table-cell sm:ps-8">{{ __('Avg Cost') }} (⃁)</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Unrealized P/L') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($holdings['rows'] as $row)
                                    <tr class="border-t border-neutral-100 dark:border-zinc-800">
                                        <td class="py-1.5">
                                            <a class="font-medium text-zinc-800 hover:underline dark:text-white"
                                                href="{{ route('holdings.detail', $row['symbol']) }}" wire:navigate>{{ $row['symbol'] }}</a>
                                            <span class="hidden text-neutral-400 sm:inline"> · {{ $row['name'] }}</span>
                                            @if ($row['shariah'] === ShariahStatus::NonCompliant)
                                                <flux:badge color="red" size="sm">{{ $row['shariah']->label() }}</flux:badge>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8">
                                            <span dir="ltr">{{ number_format($row['quantity'], 2) }}</span></td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8">
                                            <span dir="ltr">{{ number_format($row['value'], 0) }}</span></td>
                                        <td class="hidden whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:table-cell sm:ps-8">
                                            <span dir="ltr">{{ $row['avgCost'] !== null ? number_format($row['avgCost'], 2) : '—' }}</span></td>
                                        <td
                                            class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8 {{ $row['pl'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            <span dir="ltr">{{ $row['pl'] >= 0 ? '+' : '−' }}{{ number_format(abs($row['pl']), 0) }}
                                                ({{ number_format($row['plPct'] * 100, 1) }}%)</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Rebalancing plan --}}
            @if ($rebalanceOrders !== [])
                <div class="border-b border-neutral-200 py-6 dark:border-neutral-700">
                    <flux:heading size="lg">{{ __('Rebalancing Plan') }}</flux:heading>
                    <div class="mt-4 overflow-x-auto scrollbar-thin">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs uppercase tracking-wide text-neutral-400">
                                    <th class="w-full pb-2 text-start font-medium">{{ __('Asset') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-center font-medium sm:ps-8">{{ __('Action') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Units') }}</th>
                                    <th class="whitespace-nowrap pb-2 ps-4 text-end font-medium sm:ps-8">{{ __('Est. Value') }} (⃁)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rebalanceOrders as $order)
                                    <tr class="border-t border-neutral-100 dark:border-zinc-800">
                                        <td class="py-1.5 font-medium text-zinc-800 dark:text-white">{{ $order['symbol'] }}</td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-center sm:ps-8">{{ $order['side'] === 'buy' ? __('Buy') : __('Sell') }}</td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8"><span dir="ltr">{{ number_format($order['quantity'], 2) }}</span></td>
                                        <td class="whitespace-nowrap py-1.5 ps-4 text-end tabular-nums sm:ps-8"><span dir="ltr">{{ number_format($order['value'], 0) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
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
                                @if (($recommendation['evidence'] ?? []) !== [])
                                    <flux:text class="mt-0.5 text-xs" dir="ltr">
                                        {{ collect($recommendation['evidence'])->map(fn (array $evidence) => $evidence['metric'].': '.$evidence['value'])->join(' · ') }}
                                    </flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Data sources & roadmap --}}
            <div class="border-t border-neutral-200 py-6 dark:border-neutral-700">
                <flux:heading size="lg">{{ __('Data Sources & Roadmap') }}</flux:heading>
                <div class="mt-3 space-y-1.5">
                    <flux:text class="text-sm">
                        {{ __('Cash accounts connect live through the SAMA Open Banking framework (Account Information Services).') }}
                    </flux:text>
                    <flux:text class="text-sm">
                        {{ __('Brokerage holdings are imported from statements until investment-account APIs join the framework, expected around 2027. Mahafeth is built to switch to them the day they launch.') }}
                    </flux:text>
                </div>
            </div>

            <flux:text class="border-t border-neutral-200 pt-4 text-xs dark:border-neutral-700">
                {{ __('This report is educational analysis generated by Mahafeth and is not licensed financial advice.') }}
            </flux:text>
        </div>
    @endif
</div>
