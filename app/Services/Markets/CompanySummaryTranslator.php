<?php

namespace App\Services\Markets;

use App\Jobs\TranslateCompanySummaryJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Renders the Yahoo Finance company profile (English-only) in Arabic by
 * translating it once through the Claude API and caching the result for
 * the summary's lifetime. Any failure falls back to the English text.
 *
 * The Claude call can take seconds, so it runs on the queue rather than
 * inline: the page serves the English summary immediately and swaps to the
 * cached Arabic once the job lands.
 */
class CompanySummaryTranslator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    /**
     * Return the Arabic summary if it is cached, otherwise queue the
     * translation and serve English for now.
     *
     * @return array{text: string, pending: bool}
     */
    public function toArabic(string $symbol, string $summary): array
    {
        $config = config('mahafeth.ai');

        if ($config['fake'] || empty($config['api_key'])) {
            return ['text' => $summary, 'pending' => false];
        }

        $key = $this->cacheKey($symbol, $summary);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return ['text' => $cached, 'pending' => false];
        }

        // Queue the translation once (the lock stops repeat views and the
        // card's poll from piling up jobs) and serve English meanwhile.
        if (Cache::add($key.':queued', true, now()->addMinutes(10))) {
            TranslateCompanySummaryJob::dispatch($symbol, $summary);
        }

        return ['text' => $summary, 'pending' => true];
    }

    /**
     * Translate and cache the summary. Runs on the queue. A failure caches
     * the English text briefly so the card stops waiting and retries later.
     */
    public function translate(string $symbol, string $summary): void
    {
        $key = $this->cacheKey($symbol, $summary);

        try {
            // Keyed on the content hash so a revised profile re-translates
            // while the previous translation keeps serving until then.
            Cache::forever($key, $this->requestTranslation($summary));
        } catch (\Throwable $exception) {
            Log::warning('Company summary translation failed, serving English.', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            Cache::put($key, $summary, now()->addMinutes(30));
        } finally {
            Cache::forget($key.':queued');
        }
    }

    private function cacheKey(string $symbol, string $summary): string
    {
        return 'company-summary-ar:'.$symbol.':'.md5($summary);
    }

    private function requestTranslation(string $summary): string
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('mahafeth.ai.api_key'),
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout((int) config('mahafeth.ai.chat_timeout'))
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false)
            ->post(self::API_URL, [
                'model' => config('mahafeth.ai.chat_model'),
                'max_tokens' => (int) config('mahafeth.ai.chat_max_tokens'),
                'system' => 'You translate company business descriptions into Modern Standard Arabic in a natural financial register. Keep proper nouns, tickers, and product names in Latin script. Respond with the translation only — no preamble.',
                'messages' => [['role' => 'user', 'content' => $summary]],
            ])
            ->throw();

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            throw new RuntimeException('Unexpected response shape from the Claude API.');
        }

        return trim($text);
    }
}
