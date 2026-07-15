<?php

use App\Services\Analytics\DividendProjector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Volt\Component;

new class extends Component {
    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('partials.skeleton-card');
    }

    public function with(): array
    {
        $calendar = app(DividendProjector::class)->calendar(Auth::user());

        $max = $calendar === null ? 0.0 : max(array_map(
            fn (array $month): float => max($month['actual'] ?? 0.0, $month['projected'] ?? 0.0),
            $calendar['months'],
        ));

        return ['calendar' => $calendar, 'max' => $max];
    }
}; ?>

{{-- The root must always render for Livewire; collapse when there is no
     dividend history to show. --}}
<div @class(['hidden' => $calendar === null])>
    @if ($calendar !== null)
        <div class="card p-5">
            <div class="flex items-start justify-between gap-3">
                <flux:heading size="lg">{{ __('Dividend Income') }}</flux:heading>
                <flux:icon.banknotes class="size-5 text-teal-600 dark:text-teal-400" />
            </div>

            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1">
                <div>
                    <flux:text class="text-xs">{{ __('Received, last 12 months') }}</flux:text>
                    <flux:heading size="lg" dir="ltr" data-amount>
                        ⃁ {{ Number::format($calendar['trailing_total'], 0) }}</flux:heading>
                </div>
                <div>
                    <flux:text class="text-xs">{{ __('Expected, next 12 months') }}</flux:text>
                    <flux:heading class="!text-teal-700 dark:!text-teal-300" size="lg" dir="ltr" data-amount>
                        ⃁ {{ Number::format($calendar['projected_total'], 0) }}</flux:heading>
                </div>
            </div>

            <div class="mt-4 flex h-24 items-end gap-1" dir="ltr">
                @foreach ($calendar['months'] as $month)
                    @php($amount = $month['actual'] ?? $month['projected'] ?? 0.0)
                    <div class="group relative flex-1">
                        <div
                            class="w-full rounded-t {{ $month['actual'] !== null ? 'bg-teal-600 dark:bg-teal-400' : 'bg-teal-600/30 dark:bg-teal-400/30' }}"
                            style="height: {{ $max > 0 ? max(2, round($amount / $max * 88)) : 2 }}px"></div>
                        <div
                            class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-zinc-800 px-1.5 py-0.5 text-[10px] text-white group-hover:block dark:bg-zinc-700">
                            {{ \Illuminate\Support\Carbon::parse($month['month'])->translatedFormat('M Y') }}
                            · ⃁ {{ Number::format($amount, 0) }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between" dir="ltr">
                <flux:text class="text-[10px]">
                    {{ \Illuminate\Support\Carbon::parse($calendar['months'][0]['month'])->translatedFormat('M Y') }}
                </flux:text>
                <flux:text class="text-[10px]">
                    {{ \Illuminate\Support\Carbon::parse(end($calendar['months'])['month'])->translatedFormat('M Y') }}
                </flux:text>
            </div>

            <flux:text class="mt-2 text-xs">
                {{ __('Solid bars are received dividends; faded bars repeat last year\'s payments for positions you still hold.') }}
            </flux:text>
        </div>
    @endif
</div>
