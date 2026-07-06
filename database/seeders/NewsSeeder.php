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
        foreach (app(CuratedNewsProvider::class)->fetchLatest() as $item) {
            NewsItem::updateOrCreate(
                ['headline' => $item['headline']],
                [
                    'headline_ar' => $item['headline_ar'],
                    'source' => $item['source'],
                    'minutes' => $item['minutes'],
                    'symbols' => $item['symbols'],
                    'sectors' => $item['sectors'],
                    'published_at' => $item['published_at'],
                ],
            );
        }
    }
}
