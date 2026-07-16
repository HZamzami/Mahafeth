<?php

use App\Services\Analytics\WhatIfSimulator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public string $symbol;

    public bool $owned = false;

    public ?string $amount = null;

    public string $side = 'buy';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public bool $unavailable = false;

    public function mount(string $symbol, bool $owned = false): void
    {
        $this->symbol = strtoupper($symbol);
        $this->owned = $owned;
    }

    public function simulate(WhatIfSimulator $simulator): void
    {
        $this->validate(
            [
                'amount' => ['required', 'numeric', 'min:1'],
                'side' => ['required', 'in:buy,sell'],
            ],
            attributes: ['amount' => __('amount')],
        );

        $this->unavailable = false;
        $this->result = $simulator->simulate(
            Auth::user(),
            $this->symbol,
            (float) $this->amount,
            $this->side === 'sell',
        );

        if ($this->result === null) {
            $this->unavailable = true;
        }
    }

    /**
     * @return list<array{label: string, before: string, after: string, delta: float, good: bool}>
     */
    public function with(): array
    {
        if ($this->result === null) {
            return ['rows' => []];
        }

        $percent = fn (?float $value): string => $value === null ? '—' : Number::percentage($value * 100, 1);
        $number = fn (?float $value): string => $value === null ? '—' : Number::format($value, 1);

        // 'good' marks whether the move improves the metric, coloring the
        // delta chip independently of its sign.
        $definitions = [
            ['key' => 'effective_holdings', 'label' => __('Effective holdings'), 'format' => $number, 'goodWhenUp' => true],
            ['key' => 'largest_weight', 'label' => __('Largest position'), 'format' => $percent, 'goodWhenUp' => false],
            ['key' => 'volatility', 'label' => __('Volatility'), 'format' => $percent, 'goodWhenUp' => false],
            ['key' => 'sharpe', 'label' => __('Sharpe ratio'), 'format' => $number, 'goodWhenUp' => true],
            ['key' => 'average_correlation', 'label' => __('Avg. correlation'), 'format' => $number, 'goodWhenUp' => false],
            ['key' => 'compliant_weight', 'label' => __('Shariah-compliant share'), 'format' => $percent, 'goodWhenUp' => true],
        ];

        $rows = [];

        foreach ($definitions as $definition) {
            $delta = $this->result['deltas'][$definition['key']] ?? null;

            if ($delta === null) {
                continue;
            }

            $rows[] = [
                'label' => $definition['label'],
                'before' => $definition['format']($this->result['before'][$definition['key']]),
                'after' => $definition['format']($this->result['after'][$definition['key']]),
                'delta' => $delta,
                'good' => $definition['goodWhenUp'] ? $delta >= 0 : $delta <= 0,
            ];
        }

        return ['rows' => $rows];
    }
}; ?>

<div class="card p-5">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('What if?') }}</flux:heading>
        <flux:icon.beaker class="size-5 text-teal-600 dark:text-teal-400" />
    </div>
    <flux:text class="mt-1 text-sm">
        {{ __('Simulate a trade in :symbol and see how your whole portfolio would change before you place it.', ['symbol' => $symbol]) }}
    </flux:text>

    <form wire:submit="simulate" class="mt-4">
        @if ($owned)
            <flux:radio.group wire:model="side" variant="segmented" size="sm" class="mb-5">
                <flux:radio value="buy" :label="__('Buy more')" />
                <flux:radio value="sell" :label="__('Sell')" />
            </flux:radio.group>
        @endif
        <div class="flex items-end gap-2">
            <flux:input wire:model="amount" type="number" step="any" min="1" dir="ltr" class="flex-1"
                :label="__('Amount (SAR)')" placeholder="10000" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="simulate">{{ __('Simulate') }}</span>
                <span wire:loading wire:target="simulate">{{ __('Computing…') }}</span>
            </flux:button>
        </div>
    </form>

    @if ($unavailable)
        <flux:callout class="mt-4" color="amber" icon="information-circle" inline>
            <flux:callout.text>
                {{ __('Not enough price history to simulate this trade. Connect your accounts first, or try another instrument.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($result !== null)
        <div class="mt-4 space-y-2" wire:transition>
            @if ($result['health_before'] !== null)
                <div class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 dark:bg-zinc-800/60">
                    <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white">
                        {{ __('Health Score') }}</flux:text>
                    <span class="flex items-center gap-1.5 text-sm tabular-nums" dir="ltr">
                        <span>{{ $result['health_before'] }}</span>
                        <flux:icon.arrow-right class="size-3.5 text-zinc-400 rtl:hidden" />
                        <flux:icon.arrow-left class="hidden size-3.5 text-zinc-400 rtl:block" />
                        <span
                            class="font-semibold {{ $result['health_after'] >= $result['health_before'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $result['health_after'] }}</span>
                    </span>
                </div>
            @endif

            @foreach ($rows as $row)
                <div class="flex items-center justify-between px-1 py-1">
                    <flux:text class="text-sm">{{ $row['label'] }}</flux:text>
                    <span class="flex items-center gap-1.5 text-sm tabular-nums" dir="ltr">
                        <span class="text-zinc-400">{{ $row['before'] }}</span>
                        <flux:icon.arrow-right class="size-3 text-zinc-300 dark:text-zinc-600" />
                        <span
                            class="{{ $row['good'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $row['after'] }}</span>
                    </span>
                </div>
            @endforeach

            <flux:text class="pt-1 text-xs">
                {{ __('Estimates only — drawdown and beta stay historical, and prices are the latest closes.') }}
            </flux:text>
            <flux:button size="xs" variant="ghost" icon="chat-bubble-left-right"
                :href="route('advisor', ['ask' => __('I am considering :side :amount SAR of :symbol. Should I?', ['side' => $side === 'buy' ? __('buying') : __('selling'), 'amount' => $amount, 'symbol' => $symbol])])"
                wire:navigate>
                {{ __('Ask Mahafeth AI about this trade') }}</flux:button>
        </div>
    @endif
</div>
