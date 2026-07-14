<?php

namespace App\Actions;

use App\Contracts\ChatResponder;
use App\Jobs\GenerateChatReplyJob;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\Insights\PortfolioContext;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the responder for an answer grounded in the latest snapshot and
 * persists the assistant reply. Runs from GenerateChatReplyJob after
 * SendChatMessage has already persisted the user's message.
 */
class GenerateChatReply
{
    /**
     * Messages sent to the model per turn; older history is dropped to
     * keep the prompt small.
     */
    private const HISTORY_WINDOW = 20;

    private const MAX_MESSAGE_CHARS = 2000;

    public function __construct(
        private ChatResponder $responder,
        private PortfolioContext $context,
    ) {}

    public function handle(User $user, string $locale): ?AiChatMessage
    {
        $snapshot = $user->latestSnapshot();

        if ($snapshot === null) {
            return null;
        }

        // Only answer an unanswered user message; the chat may have been
        // cleared (or already answered) between dispatch and execution,
        // and an orphan assistant reply would open a fresh conversation.
        if ($user->chatMessages()->latest('id')->first()?->role !== 'user') {
            return null;
        }

        $reply = $this->responder->respond(
            $snapshot,
            $user->riskProfile,
            $locale,
            $this->context->goals($user, $snapshot),
            $this->history($user),
            // Stream the growing reply into cache; the advisor page polls
            // it so the answer appears while the model is still writing.
            fn (string $partial) => Cache::put(
                GenerateChatReplyJob::partialCacheKey($user),
                $partial,
                now()->addMinutes(3),
            ),
        );

        return $user->chatMessages()->create([
            'role' => 'assistant',
            'content' => $reply,
            'locale' => $locale,
        ]);
    }

    /**
     * The most recent messages, oldest first, each truncated so a pasted
     * wall of text cannot blow up the prompt.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(User $user): array
    {
        return $user->chatMessages()
            ->latest('id')
            ->limit(self::HISTORY_WINDOW)
            ->get()
            ->reverse()
            ->map(fn (AiChatMessage $message): array => [
                'role' => $message->role,
                'content' => mb_substr($message->content, 0, self::MAX_MESSAGE_CHARS),
            ])
            ->values()
            ->all();
    }
}
