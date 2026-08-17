<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ZakatRequest;
use App\Support\HijriDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZakatController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->present($user->zakat_hawl_month, $user->zakat_hawl_day)]);
    }

    public function update(ZakatRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'zakat_hawl_month' => $request->validated('hawl_month'),
            'zakat_hawl_day' => $request->validated('hawl_day'),
        ]);

        return response()->json(['data' => $this->present($user->zakat_hawl_month, $user->zakat_hawl_day)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->update(['zakat_hawl_month' => null, 'zakat_hawl_day' => null]);

        return response()->json(['data' => $this->present(null, null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?int $month, ?int $day): array
    {
        return [
            'hawl_month' => $month,
            'hawl_day' => $day,
            'next_date' => $month !== null && $day !== null
                ? HijriDate::nextGregorian($month, $day)->toDateString()
                : null,
        ];
    }
}
