<?php

use App\Models\Asset;
use App\Models\InvestmentPlan;
use App\Services\Analytics\InvestmentPlanBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

/**
 * The day-one starter portfolio: instead of only diagnosing an existing
 * portfolio, propose the efficient allocation that matches the investor's
 * IPS risk budget before the first riyal is invested.
 */
new class extends Component {
    private const CIRCUMFERENCE = 251.33; // 2πr with r = 40

    private const COLORS = [
        ['stroke-teal-600 dark:stroke-teal-400', 'bg-teal-600 dark:bg-teal-400'],
        ['stroke-blue-500 dark:stroke-blue-400', 'bg-blue-500 dark:bg-blue-400'],
        ['stroke-amber-500 dark:stroke-amber-400', 'bg-amber-500 dark:bg-amber-400'],
        ['stroke-purple-500 dark:stroke-purple-400', 'bg-purple-500 dark:bg-purple-400'],
        ['stroke-rose-500 dark:stroke-rose-400', 'bg-rose-500 dark:bg-rose-400'],
        ['stroke-emerald-500 dark:stroke-emerald-400', 'bg-emerald-500 dark:bg-emerald-400'],
        ['stroke-sky-500 dark:stroke-sky-400', 'bg-sky-500 dark:bg-sky-400'],
        ['stroke-neutral-300 dark:stroke-zinc-600', 'bg-neutral-300 dark:bg-zinc-600'],
    ];

    public float $amount = 100000;

    public float $monthlyContribution = 0;

    public function mount(): void
    {
        $plan = $this->currentPlan();

        if ($plan !== null) {
            $this->amount = $plan->amount;
            $this->monthlyContribution = (float) $plan->monthly_contribution;
        }
    }

    public function generate(): void
    {
        $this->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:1000000000'],
            'monthlyContribution' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $plan = app(InvestmentPlanBuilder::class)->build(Auth::user(), $this->amount, $this->monthlyContribution);

        if ($plan === null) {
            $this->dispatch('toast', message: __('Not enough market data to build a plan right now.'));

            return;
        }

        InvestmentPlan::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'amount' => $this->amount,
                'monthly_contribution' => $this->monthlyContribution,
                ...$plan,
            ],
        );

        $this->dispatch('toast', message: __('Your investment plan is ready.'));
    }

    public function with(): array
    {
        $plan = $this->currentPlan();

        return [
            'hasProfile' => Auth::user()->riskProfile()->exists(),
            'plan' => $plan,
            'segments' => $plan !== null ? $this->segments($plan) : [],
            'chart' => $plan !== null ? $this->chartPoints($plan) : [],
            'askPrompt' => $plan !== null ? $this->askPrompt($plan) : '',
        ];
    }

    private function currentPlan(): ?InvestmentPlan
    {
        return InvestmentPlan::whereBelongsTo(Auth::user())->first();
    }

    /**
     * Donut segments from the plan weights, largest first.
     *
     * @return list<array{label: string, symbol: string, weight: float, stroke: string, dot: string, dasharray: string, dashoffset: float}>
     */
    private function segments(InvestmentPlan $plan): array
    {
        $weights = $plan->weights;
        arsort($weights);

        $names = Asset::whereIn('symbol', array_keys($weights))->get()->keyBy('symbol');

        $segments = [];
        $offset = 0.0;
        $index = 0;

        foreach (array_slice($weights, 0, count(self::COLORS), true) as $symbol => $weight) {
            $length = $weight * self::CIRCUMFERENCE;

            $segments[] = [
                'label' => $names[$symbol]?->localizedName() ?? $symbol,
                'symbol' => $symbol,
                'weight' => $weight,
                'stroke' => self::COLORS[$index][0],
                'dot' => self::COLORS[$index][1],
                'dasharray' => round($length, 2).' '.round(self::CIRCUMFERENCE - $length, 2),
                'dashoffset' => round(-$offset, 2),
            ];

            $offset += $length;
            $index++;
        }

        return $segments;
    }

    /**
     * Downsampled p10/p50/p90 rows for the projection fan chart.
     *
     * @return list<array{date: string, p10: float, p50: float, p90: float}>
     */
    private function chartPoints(InvestmentPlan $plan): array
    {
        $bands = $plan->forecast['bands'] ?? [];
        $count = count($bands['p50'] ?? []);

        if ($count < 2) {
            return [];
        }

        $step = max(1, (int) ceil($count / 48));
        $points = [];

        for ($month = 0; $month < $count; $month++) {
            if ($month % $step !== 0 && $month !== $count - 1) {
                continue;
            }

            $points[] = [
                'date' => now()->addMonths($month)->toDateString(),
                'p10' => round($bands['p10'][$month], 2),
                'p50' => round($bands['p50'][$month], 2),
                'p90' => round($bands['p90'][$month], 2),
            ];
        }

        return $points;
    }

    private function askPrompt(InvestmentPlan $plan): string
    {
        $allocation = collect($plan->weights)
            ->map(fn (float $weight, string $symbol): string => $symbol.' '.round($weight * 100).'%')
            ->join(', ');

        return __('Mahafeth proposed this starter investment plan for me: :allocation, expected return :return, volatility :volatility. Explain in simple terms why it fits my investor profile and what I should watch out for.', [
            'allocation' => $allocation,
            'return' => Number::percentage(($plan->metrics['expected_return'] ?? 0) * 100, 1),
            'volatility' => Number::percentage(($plan->metrics['volatility'] ?? 0) * 100, 1),
        ]);
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Investment Plan') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Start investing at the right risk from day one: an allocation built for your profile, before the first riyal is placed.') }}
        </flux:text>
    </div>

    @if (! $hasProfile)
        <div class="flex flex-col items-center gap-4 card p-12 text-center">
            <flux:icon.clipboard-document-check class="size-8 text-zinc-400" />
            <flux:text class="text-sm">
                {{ __('Your plan is built from your investor profile — answer the short questionnaire first.') }}</flux:text>
            <flux:button variant="primary" :href="route('investor-profile')" wire:navigate>
                {{ __('Build your investor profile') }}</flux:button>
        </div>
    @else
        {{-- Inputs --}}
        <div class="card p-5">
            <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                {{ __('Your Starting Point') }}</flux:heading>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Starting amount (SAR)') }}</flux:label>
                    <flux:input type="number" min="1000" step="1000" wire:model="amount" dir="ltr" />
                    <flux:error name="amount" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Monthly contribution (SAR)') }}</flux:label>
                    <flux:input type="number" min="0" step="100" wire:model="monthlyContribution" dir="ltr" />
                    <flux:error name="monthlyContribution" />
                </flux:field>
            </div>
            <flux:button class="mt-4 w-full sm:w-auto" variant="primary" icon="rocket-launch"
                wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                <span wire:loading.remove wire:target="generate">
                    {{ $plan !== null ? __('Regenerate plan') : __('Build my plan') }}</span>
                <span wire:loading wire:target="generate">{{ __('Crunching the numbers…') }}</span>
            </flux:button>
        </div>

        @if ($plan !== null)
            {{-- Plan stats --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="card p-4">
                    <flux:text class="text-xs">{{ __('Expected Return') }}</flux:text>
                    <flux:heading class="!text-emerald-600 dark:!text-emerald-400" size="lg" dir="ltr">
                        {{ Number::percentage(($plan->metrics['expected_return'] ?? 0) * 100, 1) }}</flux:heading>
                    <flux:text class="text-xs">{{ __('per year, before fees') }}</flux:text>
                </div>
                <div class="card p-4">
                    <flux:text class="text-xs">{{ __('Volatility') }}</flux:text>
                    <flux:heading size="lg" dir="ltr">
                        {{ Number::percentage(($plan->metrics['volatility'] ?? 0) * 100, 1) }}</flux:heading>
                    <flux:text class="text-xs" dir="ltr">
                        {{ __('target :target', ['target' => Number::percentage(($plan->metrics['target_volatility'] ?? 0) * 100, 0)]) }}</flux:text>
                </div>
                <div class="card p-4">
                    <flux:text class="text-xs">{{ __('Sharpe Ratio') }}</flux:text>
                    <flux:heading size="lg" dir="ltr">{{ number_format($plan->metrics['sharpe'] ?? 0, 2) }}</flux:heading>
                    <flux:text class="text-xs">{{ __('return per unit of risk') }}</flux:text>
                </div>
                <div class="card p-4">
                    <flux:text class="text-xs">{{ __('Risk Alignment') }}</flux:text>
                    @php($alignment = $plan->metrics['risk_alignment'] ?? 0)
                    <flux:heading class="{{ $alignment >= 80 ? '!text-emerald-600 dark:!text-emerald-400' : ($alignment >= 50 ? '!text-amber-600 dark:!text-amber-400' : '!text-red-600 dark:!text-red-400') }}"
                        size="lg" dir="ltr">{{ number_format($alignment, 0) }}/100</flux:heading>
                    <flux:text class="text-xs">{{ __('fit with your risk profile') }}</flux:text>
                </div>
            </div>

            {{-- Allocation donut --}}
            <div class="card p-5">
                <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                    {{ __('Proposed Allocation') }}</flux:heading>
                <div x-data="{ active: null }" class="mt-2 grid items-center gap-6 sm:grid-cols-2">
                    <div class="relative flex items-center justify-center py-4">
                        <svg class="aspect-square w-full max-w-56 -rotate-90" viewBox="0 0 100 100">
                            @foreach ($segments as $index => $segment)
                                <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                                    class="donut-fill cursor-pointer transition-opacity {{ $segment['stroke'] }}"
                                    stroke-dasharray="0 251.33" data-dasharray="{{ $segment['dasharray'] }}"
                                    stroke-dashoffset="{{ $segment['dashoffset'] }}"
                                    x-intersect.once="$el.style.strokeDasharray = $el.dataset.dasharray"
                                    x-on:click="active = active === {{ $index }} ? null : {{ $index }}"
                                    x-bind:class="active !== null && active !== {{ $index }} && 'opacity-30'" />
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div x-show="active === null" class="flex flex-col items-center">
                                <flux:heading size="lg" dir="ltr">⃁ {{ Number::localizedAbbreviate($plan->amount, 1) }}</flux:heading>
                                <flux:text class="text-xs">{{ __('to invest') }}</flux:text>
                            </div>
                            @foreach ($segments as $index => $segment)
                                <div x-show="active === {{ $index }}" x-cloak class="flex flex-col items-center">
                                    <flux:heading size="lg" dir="ltr">
                                        {{ Number::percentage($segment['weight'] * 100, 1) }}</flux:heading>
                                    <flux:text class="max-w-28 truncate text-xs"><bdi>{{ $segment['label'] }}</bdi></flux:text>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="space-y-1">
                        @foreach ($segments as $index => $segment)
                            <button type="button"
                                class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-start transition-colors hover:bg-neutral-50 dark:hover:bg-zinc-800/60"
                                x-on:click="active = active === {{ $index }} ? null : {{ $index }}"
                                x-bind:class="active === {{ $index }} && 'bg-neutral-100 dark:bg-zinc-800'"
                                x-bind:aria-pressed="(active === {{ $index }}).toString()"
                                wire:key="segment-{{ $segment['symbol'] }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="size-2 shrink-0 rounded-full {{ $segment['dot'] }}"></span>
                                    <flux:text class="truncate text-sm"><bdi>{{ $segment['label'] }}</bdi></flux:text>
                                </span>
                                <flux:text class="shrink-0 text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                                    {{ Number::percentage($segment['weight'] * 100, 1) }}</flux:text>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Growth projection --}}
            @if ($chart !== [])
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('How It Could Grow') }}</flux:heading>
                    <flux:chart class="aspect-2/1 relative mt-4" dir="ltr" :value="$chart">
                        <flux:chart.svg>
                            <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p10" stroke-dasharray="4 4" />
                            <flux:chart.line class="text-teal-600 dark:text-teal-400" curve="smooth" field="p50" />
                            <flux:chart.line class="text-neutral-300 dark:text-zinc-600" field="p90" stroke-dasharray="4 4" />
                            <flux:chart.axis axis="x" field="date" tick-count="5" :format="['year' => 'numeric']">
                                <flux:chart.axis.tick />
                            </flux:chart.axis>
                            <flux:chart.axis axis="y" tick-count="4" :format="['notation' => 'compact']">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>
                            <flux:chart.point class="text-teal-600 dark:text-teal-400" field="p50" />
                            <flux:chart.cursor />
                        </flux:chart.svg>

                        <flux:chart.tooltip class="max-w-44">
                            <flux:chart.tooltip.heading field="date" :format="['month' => 'short', 'year' => 'numeric']" />
                            <flux:chart.tooltip.value :label="__('Most likely path')" field="p50" :format="['notation' => 'compact']" />
                            <flux:chart.tooltip.value :label="__('Worse case')" field="p10" :format="['notation' => 'compact']" />
                            <flux:chart.tooltip.value :label="__('Better case')" field="p90" :format="['notation' => 'compact']" />
                        </flux:chart.tooltip>
                    </flux:chart>
                    <flux:text class="mt-2 text-center text-xs">
                        {{ __('1,000 simulated futures over :years years — 8 in 10 land between the dashed lines.', ['years' => (int) (($plan->forecast['months'] ?? 0) / 12)]) }}
                    </flux:text>
                </div>
            @endif

            {{-- Starter buy list --}}
            @if ($plan->orders !== [])
                <div class="card p-5">
                    <flux:heading class="uppercase tracking-widest !text-neutral-500 dark:!text-neutral-400" size="sm">
                        {{ __('Your Starter Buy List') }}</flux:heading>
                    <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($plan->orders as $order)
                            <div class="flex items-center justify-between gap-3 py-2.5" wire:key="order-{{ $order['symbol'] }}">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        <bdi>{{ $order['name'] }}</bdi></span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400" dir="ltr">
                                        {{ $order['symbol'] }} &bull; {{ Number::percentage($order['weight'] * 100, 1) }}</span>
                                </span>
                                <span class="shrink-0 text-end">
                                    <span class="block text-sm font-medium tabular-nums text-zinc-800 dark:text-white" dir="ltr">
                                        ⃁ {{ number_format($order['value'], 0) }}</span>
                                    <span class="block text-xs tabular-nums text-zinc-500 dark:text-zinc-400" dir="ltr">
                                        ≈ {{ number_format($order['quantity'], 2) }} {{ __('units') }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <flux:button class="w-full" variant="primary" icon="chat-bubble-left-right"
                :href="route('advisor', ['ask' => $askPrompt])" wire:navigate>
                {{ __('Ask Mahafeth AI about this plan') }}</flux:button>

            @if ($plan->metrics['shariah_applied'] ?? false)
                <flux:callout icon="check-badge" color="emerald" inline>
                    <flux:callout.text>
                        {{ __('Only Shariah-compliant instruments were considered, per your profile.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:callout icon="information-circle" inline>
                <flux:callout.text>
                    {{ __('Built from historical market data — future returns are not guaranteed. Educational analysis, not licensed financial advice.') }}
                </flux:callout.text>
            </flux:callout>
        @endif
    @endif
</div>
