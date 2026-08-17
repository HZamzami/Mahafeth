<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ObligationKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SettleObligationRequest;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Support\HijriDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShariahController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $metrics = $user->latestSnapshot()?->metrics;
        $shariah = $metrics['shariah'] ?? null;

        return response()->json([
            'data' => [
                'shariah' => $shariah,
                'zakat' => $metrics['zakat'] ?? null,
                'outstanding' => $shariah === null ? 0.0 : ($shariah['purification_outstanding'] ?? $shariah['purification_amount'] ?? 0.0),
                'purified_through' => $shariah['last_purified_through'] ?? null,
                'settlements' => $user->obligationSettlements()
                    ->where('kind', ObligationKind::Purification)
                    ->latest('settled_through')
                    ->limit(5)
                    ->get()
                    ->map(fn ($settlement): array => [
                        'id' => $settlement->id,
                        'amount' => $settlement->amount,
                        'settled_through' => $settlement->settled_through->toDateString(),
                    ]),
                'hawl' => $this->hawl($user),
            ],
        ]);
    }

    public function storePurification(SettleObligationRequest $request, PortfolioAnalyzer $analyzer): JsonResponse
    {
        $user = $request->user();

        $user->obligationSettlements()->create([
            'kind' => ObligationKind::Purification,
            'amount' => $request->validated('amount'),
            'settled_through' => today()->toDateString(),
        ]);

        $analyzer->analyze($user);

        return response()->json(['data' => ['message' => __('Purification recorded.')]], 201);
    }

    public function storeZakatPayment(SettleObligationRequest $request): JsonResponse
    {
        $request->user()->obligationSettlements()->create([
            'kind' => ObligationKind::Zakat,
            'amount' => $request->validated('amount'),
            'settled_through' => today()->toDateString(),
        ]);

        return response()->json(['data' => ['message' => __('Zakat payment recorded.')]], 201);
    }

    /**
     * @return array{next: string, paid: bool, paid_on: ?string}|null
     */
    private function hawl(User $user): ?array
    {
        if ($user->zakat_hawl_month === null || $user->zakat_hawl_day === null) {
            return null;
        }

        $next = HijriDate::nextGregorian($user->zakat_hawl_month, $user->zakat_hawl_day);
        $previous = HijriDate::gregorian(
            HijriDate::toHijri($next)['year'] - 1,
            $user->zakat_hawl_month,
            $user->zakat_hawl_day,
        );

        $paidOn = $user->settledThrough(ObligationKind::Zakat);

        return [
            'next' => $next->toDateString(),
            'paid' => $paidOn !== null && $paidOn->gte($previous),
            'paid_on' => $paidOn?->toDateString(),
        ];
    }
}
