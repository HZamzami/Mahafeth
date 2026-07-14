<?php

namespace App\Actions;

use App\Jobs\GenerateChatReplyJob;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Persists a user's advisor chat message and queues the assistant reply,
 * so the message shows up in the thread immediately while the model
 * composes in the background (GenerateChatReplyJob → GenerateChatReply).
 */
class SendChatMessage
{
    public function handle(User $user, string $content, string $locale): AiChatMessage
    {
        $message = $user->chatMessages()->create([
            'role' => 'user',
            'content' => $content,
            'locale' => $locale,
        ]);

        // The awaiting flag must be set before dispatch: on the sync queue
        // the job runs inline and clears it again in its finally block.
        Cache::forget(GenerateChatReplyJob::failedCacheKey($user));
        Cache::put(GenerateChatReplyJob::awaitingCacheKey($user), true, now()->addMinutes(5));
        GenerateChatReplyJob::dispatch($user, $locale);

        return $message;
    }
}
