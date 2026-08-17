<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\FundamentalsProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WhatIfRequest;
use App\Services\Analytics\WhatIfSimulator;
use App\Services\Markets\AssetResolver;
use App\Services\Markets\CompanySummaryTranslator;
use Illuminate\Http\JsonResponse;

class InstrumentController extends Controller
{
    public function show(string $symbol, AssetResolver $resolver, FundamentalsProvider $fundamentalsProvider, CompanySummaryTranslator $translator): JsonResponse
    {
        $symbol = strtoupper($symbol);
        $resolver->resolve($symbol);

        $fundamentals = $fundamentalsProvider->fetch($symbol);

        if ($fundamentals === null) {
            return response()->json(['data' => null]);
        }

        // The web page serves the English summary immediately and swaps to
        // Arabic once the translation job lands; the API mirrors that as a
        // `summary_pending` flag the client polls the same endpoint against.
        if (app()->getLocale() === 'ar' && ($fundamentals['profile']['summary'] ?? null) !== null) {
            $translated = $translator->toArabic($symbol, $fundamentals['profile']['summary']);
            $fundamentals['profile']['summary'] = $translated['text'];
            $fundamentals['summary_pending'] = $translated['pending'];
        } else {
            $fundamentals['summary_pending'] = false;
        }

        return response()->json(['data' => $fundamentals]);
    }

    public function whatIf(WhatIfRequest $request, string $symbol, WhatIfSimulator $simulator): JsonResponse
    {
        $result = $simulator->simulate(
            $request->user(),
            $symbol,
            $request->validated('amount'),
            $request->isSell(),
        );

        if ($result === null) {
            return response()->json([
                'message' => __('Not enough price history to simulate this trade.'),
            ], 422);
        }

        return response()->json(['data' => $result]);
    }
}
