<?php

namespace App\Jobs;

use App\Actions\GenerateChatReply;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class GenerateChatReplyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Timeout invariant: HTTP worst case (60s chat timeout + 10s connect,
     * no retries) < this < DB_QUEUE_RETRY_AFTER (180) ≤ queue:listen
     * --timeout (200), so a slow reply can never be re-served mid-run or
     * kill the worker.
     */
    public int $timeout = 90;

    public function __construct(public User $user, public string $locale) {}

    /**
     * One in-flight reply per user: the composer is disabled while a
     * reply is awaited, so a second concurrent send is always a bug.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    /**
     * Cache flag the UI polls to show the typing indicator. Set by
     * SendChatMessage on dispatch, refreshed here when the job starts so
     * a long queue wait cannot outlive it, cleared whatever the outcome.
     */
    public static function awaitingCacheKey(User $user): string
    {
        return "chat:awaiting:{$user->id}";
    }

    /**
     * Cache flag the UI reads to show a "could not be reached, retry"
     * state instead of a typing indicator that silently gives up.
     */
    public static function failedCacheKey(User $user): string
    {
        return "chat:failed:{$user->id}";
    }

    public function handle(GenerateChatReply $generateChatReply): void
    {
        Cache::put(self::awaitingCacheKey($this->user), true, now()->addSeconds($this->timeout + 30));

        try {
            $generateChatReply->handle($this->user, $this->locale);
        } catch (\Throwable $exception) {
            Cache::put(self::failedCacheKey($this->user), true, now()->addMinutes(10));

            throw $exception;
        } finally {
            Cache::forget(self::awaitingCacheKey($this->user));
        }
    }

    public function failed(): void
    {
        Cache::put(self::failedCacheKey($this->user), true, now()->addMinutes(10));
        Cache::forget(self::awaitingCacheKey($this->user));
    }
}
