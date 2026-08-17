<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SendChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendMessageRequest;
use App\Http\Resources\Api\V1\ChatMessageResource;
use App\Jobs\GenerateChatReplyJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class AdvisorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChatMessageResource::collection(
            $request->user()->chatMessages()->latest('id')->paginate(30)
        );
    }

    public function store(SendMessageRequest $request, SendChatMessage $sendChatMessage): JsonResponse
    {
        $user = $request->user();

        if (Cache::has(GenerateChatReplyJob::awaitingCacheKey($user))) {
            return response()->json(['message' => __('A reply is already being composed.')], 409);
        }

        $message = $sendChatMessage->handle($user, $request->validated('content'), app()->getLocale());

        return response()->json([
            'data' => (new ChatMessageResource($message))->resolve($request),
        ], 202);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'awaiting' => Cache::has(GenerateChatReplyJob::awaitingCacheKey($user)),
                'partial' => Cache::get(GenerateChatReplyJob::partialCacheKey($user), ''),
                'failed' => Cache::has(GenerateChatReplyJob::failedCacheKey($user)),
            ],
        ]);
    }
}
