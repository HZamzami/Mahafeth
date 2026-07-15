<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fx\FxRateService;
use Carbon\CarbonInterface;

/**
 * External cash flows in and out of the user's connected accounts. Buys
 * and sells move money between tracked cash and tracked assets, so only
 * deposits and withdrawals count as contributions. Amounts convert at
 * current FX rates (no rate history exists), an accepted approximation.
 */
class NetFlowCalculator
{
    public function __construct(private FxRateService $fxRateService) {}

    /**
     * @return array{deposits: float, withdrawals: float, net: float}
     */
    public function flows(User $user, CarbonInterface $from): array
    {
        $transactions = Transaction::with('asset')
            ->whereIn('type', [TransactionType::Deposit, TransactionType::Withdrawal])
            ->where('executed_at', '>=', $from)
            ->whereHas('account.connection', fn ($query) => $query
                ->whereBelongsTo($user)
                ->where('status', ConnectionStatus::Connected))
            ->get();

        $deposits = 0.0;
        $withdrawals = 0.0;

        foreach ($transactions as $transaction) {
            $amount = abs($transaction->amount) * $this->fxRateService->rate($transaction->asset->currency ?? config('mahafeth.base_currency'));

            if ($transaction->type === TransactionType::Deposit) {
                $deposits += $amount;
            } else {
                $withdrawals += $amount;
            }
        }

        return [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'net' => round($deposits - $withdrawals, 2),
        ];
    }
}
