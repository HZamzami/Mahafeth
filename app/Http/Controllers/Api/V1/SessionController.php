<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $tokens = $request->user()->tokens()->orderByDesc('last_used_at')->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'is_current' => $token->id === $currentId,
            ]);

        return response()->json(['data' => $tokens]);
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['data' => ['message' => __('Device signed out.')]]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $request->user()->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json(['data' => ['message' => __('All other devices have been signed out.')]]);
    }
}
