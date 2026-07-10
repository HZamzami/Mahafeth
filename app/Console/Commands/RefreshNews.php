<?php

namespace App\Console\Commands;

use App\Contracts\NewsProvider;
use App\Models\NewsItem;
use Illuminate\Console\Command;

class RefreshNews extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:refresh-news';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull the latest portfolio-relevant news into the feed and prune stale items';

    private const KEEP_DAYS = 14;

    /**
     * Execute the console command.
     */
    public function handle(NewsProvider $newsProvider): int
    {
        $items = $newsProvider->fetchLatest();

        foreach ($items as $item) {
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

        $pruned = NewsItem::where('published_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        // A fully-linked batch means the live provider answered; drop any
        // leftover synthetic curated headlines (always url-less) so they
        // stop shadowing real, clickable articles. When the provider fell
        // back to curated headlines the batch contains null urls, and the
        // synthetic feed is kept.
        if ($items !== [] && collect($items)->every(fn (array $item): bool => ! empty($item['url']))) {
            $pruned += NewsItem::whereNull('url')->delete();
        }

        $this->components->info(sprintf('Stored %d items, pruned %d stale ones.', count($items), $pruned));

        return self::SUCCESS;
    }
}
