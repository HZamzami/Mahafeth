<?php

namespace App\Services\Markets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Renders the Yahoo Finance company profile (English-only) in Arabic by
 * translating it once through the Claude API and caching the result for
 * the summary's lifetime. Any failure falls back to the English text.
 */
class CompanySummaryTranslator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function toArabic(string $symbol, string $summary): string
    {
        $config = config('mahafeth.ai');

        if ($config['fake'] || empty($config['api_key'])) {
            return $summary;
        }

        // Keyed on the content hash so a revised profile re-translates while
        // the previous translation keeps serving until then.
        $key = 'company-summary-ar:'.$symbol.':'.md5($summary);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $translation = $this->requestTranslation($summary);
            Cache::forever($key, $translation);

            return $translation;
        } catch (\Throwable $exception) {
            Log::warning('Company summary translation failed, serving English.', [
                'symbol' => $symbol,
                'error' => $exception->getMessage(),
            ]);

            return $summary;
        }
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
