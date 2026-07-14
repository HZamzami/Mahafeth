<?php

namespace App\Jobs;

use App\Actions\GenerateInsights;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class GenerateInsightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Timeout invariant: HTTP worst case (120s Claude timeout + 10s
     * connect, no retries) < this < DB_QUEUE_RETRY_AFTER (180) ≤
     * queue:listen --timeout (200), so a slow generation can never be
     * re-served mid-run or kill the worker.
     */
    public int $timeout = 150;

    public function __construct(public User $user, public string $locale) {}

    /**
     * Flag a generation as in flight and queue it — the shared entry
     * point for every "Generate Insights" button. The flag's TTL only
     * covers the queue wait; handle() refreshes it when work starts.
     */
    public static function request(User $user, string $locale): void
    {
        Cache::forget(self::failedCacheKey($user, $locale));
        Cache::put(self::cacheKey($user, $locale), true, now()->addMinutes(10));
        self::dispatch($user, $locale);
    }

    public function uniqueId(): string
    {
        return $this->user->id.':'.$this->locale;
    }

    /**
     * Cache flag the UI polls to know a generation is in flight. Set by
     * the dispatching component, cleared here whatever the outcome; the
     * TTL is the backstop when a worker dies without running failed().
     */
    public static function cacheKey(User $user, string $locale): string
    {
        return "insights:generating:{$user->id}:{$locale}";
    }

    /**
     * Cache flag the UI reads to show a "generation failed, try again"
     * state instead of a spinner that silently gives up. Cleared when the
     * user retries; the TTL keeps a stale failure from lingering.
     */
    public static function failedCacheKey(User $user, string $locale): string
    {
        return "insights:failed:{$user->id}:{$locale}";
    }

    public function handle(GenerateInsights $generateInsights): void
    {
        // Refresh the flag now that work has started, so a long queue
        // wait cannot leave the UI spinner-less mid-generation.
        Cache::put(self::cacheKey($this->user, $this->locale), true, now()->addSeconds($this->timeout + 30));

        try {
            $generateInsights->handle($this->user, $this->locale);
        } catch (\Throwable $exception) {
            Cache::put(self::failedCacheKey($this->user, $this->locale), true, now()->addMinutes(10));

            throw $exception;
        } finally {
            Cache::forget(self::cacheKey($this->user, $this->locale));
        }
    }

    public function failed(): void
    {
        Cache::put(self::failedCacheKey($this->user, $this->locale), true, now()->addMinutes(10));
        Cache::forget(self::cacheKey($this->user, $this->locale));
    }
}
