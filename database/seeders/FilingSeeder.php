<?php

namespace Database\Seeders;

use App\Models\CompanyFiling;
use App\Services\Filings\CuratedFilingProvider;
use Illuminate\Database\Seeder;

class FilingSeeder extends Seeder
{
    /**
     * Seed curated company filings tied to the catalog's assets, so the
     * disclosures card can match filings to what each user holds. The
     * fixtures live in the curated filing provider, which also serves as
     * the runtime source until a live SEC or Tadawul feed is wired in.
     */
    public function run(): void
    {
        foreach (app(CuratedFilingProvider::class)->fetchLatest() as $filing) {
            CompanyFiling::updateOrCreate(
                ['headline' => $filing['headline']],
                [
                    'headline_ar' => $filing['headline_ar'],
                    'symbol' => $filing['symbol'],
                    'type' => $filing['type'],
                    'source' => $filing['source'],
                    'url' => $filing['url'],
                    'excerpt' => $filing['excerpt'],
                    'excerpt_ar' => $filing['excerpt_ar'],
                    'published_at' => $filing['published_at'],
                ],
            );
        }
    }
}
