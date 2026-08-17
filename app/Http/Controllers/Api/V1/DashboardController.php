<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Jobs\AnalyzePortfolioJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $snapshot = $request->user()->latestSnapshot();

        if ($snapshot === null) {
            return response()->json(['data' => ['has_snapshot' => false]]);
        }

        $previous = $request->user()->portfolioSnapshots()
            ->where('as_of', '<', $snapshot->as_of)
            ->latest('as_of')
            ->first();

        return response()->json([
            'data' => (new DashboardResource($snapshot, $previous))->resolve($request),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        AnalyzePortfolioJob::dispatch($request->user());

        return response()->json([
            'data' => ['message' => __('Your portfolio analysis has been queued.')],
        ], 202);
    }
}
