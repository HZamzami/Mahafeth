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
    /**
     * marketaux entity industries mapped onto the GICS sector names the
     * rest of the platform uses, so news-to-portfolio matching keeps working.
     *
     * @var array<string, string>
     */
    private const array INDUSTRY_TO_GICS = [
        'Technology' => 'Information Technology',
        'Financial' => 'Financials',
        'Financial Services' => 'Financials',
        'Communication Services' => 'Communication Services',
        'Consumer Cyclical' => 'Consumer Discretionary',
        'Consumer Defensive' => 'Consumer Staples',
        'Healthcare' => 'Health Care',
        'Basic Materials' => 'Materials',
        'Industrials' => 'Industrials',
        'Energy' => 'Energy',
        'Utilities' => 'Utilities',
        'Real Estate' => 'Real Estate',
    ];

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
                'url' => $article['url'] ?? null,
                'minutes' => max(1, (int) ceil(str_word_count($article['description'] ?? '') / 200) + 2),
                'symbols' => array_values(array_unique(array_column($article['entities'] ?? [], 'symbol'))) ?: null,
                'sectors' => array_values(array_unique(array_map(
                    fn (string $industry): string => self::INDUSTRY_TO_GICS[$industry] ?? $industry,
                    array_filter(array_column($article['entities'] ?? [], 'industry')),
                ))) ?: null,
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
