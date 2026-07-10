<?php

namespace App\Console\Commands;

use App\Contracts\FilingProvider;
use App\Models\CompanyFiling;
use Illuminate\Console\Command;

class RefreshFilings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:refresh-filings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull the latest company filings and disclosures and prune stale ones';

    /**
     * Filings stay relevant far longer than news headlines.
     */
    private const KEEP_DAYS = 90;

    /**
     * Execute the console command.
     */
    public function handle(FilingProvider $filingProvider): int
    {
        $filings = $filingProvider->fetchLatest();

        foreach ($filings as $filing) {
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

        $pruned = CompanyFiling::where('published_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        $this->components->info(sprintf('Stored %d filings, pruned %d stale ones.', count($filings), $pruned));

        return self::SUCCESS;
    }
}
