<?php

namespace App\Actions;

use App\Contracts\ChatResponder;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\Insights\PortfolioContext;

/**
 * Persists a user's advisor chat message, asks the responder for an
 * answer grounded in the latest snapshot, and persists the reply.
 */
class SendChatMessage
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

    public function handle(User $user, string $content, string $locale): AiChatMessage
    {
        $snapshot = $user->latestSnapshot();

        $user->chatMessages()->create([
            'role' => 'user',
            'content' => $content,
            'locale' => $locale,
        ]);

        $reply = $this->responder->respond(
            $snapshot,
            $user->riskProfile,
            $locale,
            $this->context->goals($user, $snapshot),
            $this->history($user),
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
