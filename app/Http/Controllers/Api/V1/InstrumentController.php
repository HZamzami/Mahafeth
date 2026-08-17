<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\FundamentalsProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WhatIfRequest;
use App\Services\Analytics\WhatIfSimulator;
use App\Services\Markets\AssetResolver;
use Illuminate\Http\JsonResponse;

class InstrumentController extends Controller
{
    public function show(string $symbol, AssetResolver $resolver, FundamentalsProvider $fundamentalsProvider): JsonResponse
    {
        $symbol = strtoupper($symbol);
        $resolver->resolve($symbol);

        $fundamentals = $fundamentalsProvider->fetch($symbol);

        if ($fundamentals === null) {
            return response()->json(['data' => null]);
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
