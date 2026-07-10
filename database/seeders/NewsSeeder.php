<?php

namespace Database\Seeders;

use App\Models\NewsItem;
use App\Services\News\CuratedNewsProvider;
use Illuminate\Database\Seeder;

class NewsSeeder extends Seeder
{
    /**
     * Seed curated market news tagged to the catalog's assets and sectors,
     * so the dashboard feed can match items to what each user holds. The
     * headlines live in the curated news provider, which also serves as
     * the runtime fallback when no live news API is configured.
     */
    public function run(): void
    {
        // With a live news API configured, the refresh command owns the
        // feed; seeding synthetic headlines here would shadow real,
        // clickable articles (curated items are always "hours old").
        if (! empty(config('services.marketaux.token'))) {
            return;
        }

        foreach (app(CuratedNewsProvider::class)->fetchLatest() as $item) {
            NewsItem::updateOrCreate(
                ['headline' => $item['headline']],
                [
                    'headline_ar' => $item['headline_ar'],
                    'source' => $item['source'],
                    'url' => $item['url'],
                    'minutes' => $item['minutes'],
                    'symbols' => $item['symbols'],
                    'sectors' => $item['sectors'],
                    'published_at' => $item['published_at'],
                ],
            );
        }
    }
}
