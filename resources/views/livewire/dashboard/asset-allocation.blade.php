<?php

use App\Enums\AssetClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    private const CIRCUMFERENCE = 251.33; // 2πr with r = 40

    private const COLORS = [
        ['stroke-blue-500 dark:stroke-blue-400', 'bg-blue-500 dark:bg-blue-400'],
        ['stroke-emerald-500 dark:stroke-emerald-400', 'bg-emerald-500 dark:bg-emerald-400'],
        ['stroke-amber-500 dark:stroke-amber-400', 'bg-amber-500 dark:bg-amber-400'],
        ['stroke-purple-500 dark:stroke-purple-400', 'bg-purple-500 dark:bg-purple-400'],
        ['stroke-rose-500 dark:stroke-rose-400', 'bg-rose-500 dark:bg-rose-400'],
        ['stroke-neutral-300 dark:stroke-zinc-700', 'bg-neutral-300 dark:bg-zinc-700'],
    ];

    /**
     * Donut segments from the latest snapshot's asset-class allocation.
     */
    public function with(): array
    {
        $snapshot = Auth::user()->latestSnapshot();
        $allocations = $snapshot?->metrics['allocations']['asset_class'] ?? [];

        $segments = [];
        $offset = 0.0;
        $index = 0;

        foreach (array_slice($allocations, 0, count(self::COLORS), true) as $class => $weight) {
            $length = $weight * self::CIRCUMFERENCE;

            $segments[] = [
                'label' => AssetClass::tryFrom($class)?->label() ?? $class,
                'weight' => $weight,
                'stroke' => self::COLORS[$index][0],
                'dot' => self::COLORS[$index][1],
                'dasharray' => round($length, 2).' '.round(self::CIRCUMFERENCE - $length, 2),
                'dashoffset' => round(-$offset, 2),
            ];

            $offset += $length;
            $index++;
        }

        return [
            'segments' => $segments,
            'totalValue' => $snapshot?->total_value,
        ];
    }
}; ?>

<div
    class="flex grow flex-col card p-5">
    <flux:heading size="lg">{{ __('Asset Allocation') }}</flux:heading>

    @if ($segments !== [])
        <div class="relative flex grow items-center justify-center py-6">
            <svg class="aspect-square w-full max-w-64 -rotate-90" viewBox="0 0 100 100">
                @foreach ($segments as $segment)
                    <circle cx="50" cy="50" r="40" fill="transparent" stroke-width="12"
                        class="{{ $segment['stroke'] }}" stroke-dasharray="{{ $segment['dasharray'] }}"
                        stroke-dashoffset="{{ $segment['dashoffset'] }}" />
                @endforeach
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <flux:heading size="lg" dir="ltr">⃁ {{ Number::abbreviate($totalValue, 1) }}</flux:heading>
                <flux:text class="text-xs">{{ __('Total') }}</flux:text>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            @foreach ($segments as $segment)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $segment['dot'] }}"></span>
                        <flux:text class="text-sm">{{ $segment['label'] }}</flux:text>
                    </div>
                    <flux:text class="text-sm font-medium !text-zinc-800 dark:!text-white" dir="ltr">
                        {{ Number::percentage($segment['weight'] * 100, 1) }}</flux:text>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex grow flex-col items-center justify-center gap-3 py-12">
            <flux:text class="text-sm">{{ __('No sources connected yet') }}</flux:text>
            <flux:button size="sm" variant="primary" :href="route('connections')" wire:navigate>
                {{ __('Connect accounts') }}</flux:button>
        </div>
    @endif
</div>
