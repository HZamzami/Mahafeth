<?php

use App\Services\Analytics\CorrelationAnalyzer;
use App\Services\Analytics\CovarianceMatrixService;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Correlation assessment of the user's unified portfolio over the
     * trailing year.
     */
    public function with(): array
    {
        $data = app(PortfolioDataAssembler::class)->forUser(Auth::user(), now()->subYear());

        if (count($data['priceSeries']) < 2) {
            return ['symbols' => [], 'correlation' => [], 'averageCorrelation' => 0.0, 'stressAverage' => 0.0];
        }

        $aligned = app(ReturnCalculator::class)->alignedLogReturns($data['priceSeries']);
        $covariance = app(CovarianceMatrixService::class)->matrix($aligned);

        $analyzer = app(CorrelationAnalyzer::class);
        $correlation = $analyzer->matrix($covariance);
        $averageCorrelation = $analyzer->averageCorrelation($correlation);

        return [
            'symbols' => array_keys($correlation),
            'correlation' => $correlation,
            'averageCorrelation' => $averageCorrelation,
            'stressAverage' => $analyzer->stressCorrelation($averageCorrelation),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Portfolio Analytics') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('How your assets move together across all connected accounts.') }}
        </flux:text>
    </div>

    @if ($symbols === [])
        <div
            class="flex items-center justify-center rounded-xl border border-neutral-200 bg-white p-16 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:text>{{ __('Connect at least two holdings to see correlation analytics.') }}</flux:text>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Average Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($averageCorrelation, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Lower values generally indicate better diversification.') }}</flux:text>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                <flux:text class="mb-1 text-xs font-medium uppercase tracking-widest">{{ __('Stress Correlation') }}
                </flux:text>
                <flux:heading size="xl" dir="ltr">{{ number_format($stressAverage, 2) }}</flux:heading>
                <flux:text class="mt-2 text-xs">
                    {{ __('Estimated average correlation during a market crisis, when assets tend to fall together.') }}
                </flux:text>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ __('Correlation Matrix') }}</flux:heading>
            <flux:text class="mb-4 mt-1 text-sm">
                {{ __('Values near 1 mean two assets move together; near 0, independently; below 0, in opposite directions.') }}
            </flux:text>

            <div class="overflow-x-auto" dir="ltr">
                <table class="w-full border-separate border-spacing-1 text-center text-xs">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($symbols as $symbol)
                                <th class="p-1 font-medium text-neutral-500 dark:text-neutral-400">{{ $symbol }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($symbols as $row)
                            <tr>
                                <th class="p-1 text-start font-medium text-neutral-500 dark:text-neutral-400">
                                    {{ $row }}</th>
                                @foreach ($symbols as $column)
                                    @php($value = $correlation[$row][$column])
                                    <td class="rounded-md p-2 tabular-nums {{ $value >= 0.65 ? 'text-white' : 'text-zinc-800 dark:text-white' }}"
                                        style="background-color: {{ $value >= 0 ? 'rgba(59, 130, 246, '.round(min(1, $value) * 0.85, 2).')' : 'rgba(239, 68, 68, '.round(min(1, -$value) * 0.85, 2).')' }}">
                                        {{ number_format($value, 2) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
