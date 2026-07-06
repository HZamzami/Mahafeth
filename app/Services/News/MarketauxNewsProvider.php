<?php

namespace App\Services\News;

use App\Contracts\NewsProvider;
use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live market news from the marketaux API, filtered to symbols actually
 * held in the system so the feed stays portfolio-relevant. Falls back to
 * the curated provider on any API failure.
 */
class MarketauxNewsProvider implements NewsProvider
{
    public function __construct(private CuratedNewsProvider $fallback) {}

    public function fetchLatest(): array
    {
        $symbols = Asset::where('is_benchmark', false)->pluck('symbol')->take(20);

        if ($symbols->isEmpty()) {
            return [];
        }

        try {
            $articles = Http::baseUrl(config('services.marketaux.base_url'))
                ->timeout(15)
                ->get('/v1/news/all', [
                    'api_token' => config('services.marketaux.token'),
                    'symbols' => $symbols->join(','),
                    'language' => 'en',
                    'limit' => 25,
                ])
                ->throw()
                ->json('data', []);

            return array_map(fn (array $article): array => [
                'headline' => $article['title'],
                'headline_ar' => $article['title'],
                'source' => $article['source'] ?? 'marketaux',
                'minutes' => max(1, (int) ceil(str_word_count($article['description'] ?? '') / 200) + 2),
                'symbols' => array_values(array_unique(array_column($article['entities'] ?? [], 'symbol'))) ?: null,
                'sectors' => array_values(array_filter(array_unique(array_column($article['entities'] ?? [], 'industry')))) ?: null,
                'published_at' => Carbon::parse($article['published_at'] ?? now()),
            ], $articles);
        } catch (\Throwable $exception) {
            Log::warning('marketaux news fetch failed, using curated headlines.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->fetchLatest();
        }
    }
}
