<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\TransactionType;
use App\Models\Holding;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fx\FxRateService;

/**
 * The user's dividend income: actual receipts bucketed by month over the
 * trailing year, plus a simple forward projection that repeats last year's
 * payments for symbols still held. Deliberately transaction-based — no
 * external estimates — so it is deterministic and works offline.
 */
class DividendProjector
{
    public function __construct(private FxRateService $fxRateService) {}

    /**
     * @return array{
     *     months: list<array{month: string, actual: ?float, projected: ?float}>,
     *     trailing_total: float,
     *     projected_total: float
     * }|null null when no dividends were received in the trailing year
     */
    public function calendar(User $user): ?array
    {
        $dividends = Transaction::with('asset')
            ->where('type', TransactionType::Dividend)
            ->where('executed_at', '>=', now()->subYear()->startOfMonth())
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get()
            ->filter(fn (Transaction $transaction): bool => $transaction->asset !== null);

        if ($dividends->isEmpty()) {
            return null;
        }

        $heldSymbols = Holding::with('asset')
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn (Holding $holding): string => $holding->asset->symbol)
            ->unique()
            ->all();

        $actuals = [];
        $projections = [];

        foreach ($dividends as $transaction) {
            $amount = $transaction->amount * $this->fxRateService->rate($transaction->asset->currency);

            $month = $transaction->executed_at->format('Y-m');
            $actuals[$month] = ($actuals[$month] ?? 0.0) + $amount;

            // Repeat next year only for positions the user still holds.
            if (in_array($transaction->asset->symbol, $heldSymbols, true)) {
                $futureMonth = $transaction->executed_at->copy()->addYear()->format('Y-m');
                $projections[$futureMonth] = ($projections[$futureMonth] ?? 0.0) + $amount;
            }
        }

        $months = [];

        foreach (range(-11, 12) as $offset) {
            $month = now()->startOfMonth()->addMonths($offset);
            $key = $month->format('Y-m');
            $isPast = $offset <= 0;

            $months[] = [
                'month' => $month->toDateString(),
                'actual' => $isPast ? round($actuals[$key] ?? 0.0, 2) : null,
                'projected' => $isPast ? null : round($projections[$key] ?? 0.0, 2),
            ];
        }

        return [
            'months' => $months,
            'trailing_total' => round(array_sum($actuals), 2),
            'projected_total' => round(array_sum($projections), 2),
        ];
    }
}
