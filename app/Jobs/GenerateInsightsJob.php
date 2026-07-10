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
     * The Claude call runs up to 120s plus retries; DB_QUEUE_RETRY_AFTER
     * must stay above this or the job gets re-served mid-run.
     */
    public int $timeout = 150;

    public function __construct(public User $user, public string $locale) {}

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

    public function handle(GenerateInsights $generateInsights): void
    {
        try {
            $generateInsights->handle($this->user, $this->locale);
        } finally {
            Cache::forget(self::cacheKey($this->user, $this->locale));
        }
    }

    public function failed(): void
    {
        Cache::forget(self::cacheKey($this->user, $this->locale));
    }
}
