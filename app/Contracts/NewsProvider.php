<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

interface NewsProvider
{
    /**
     * Fetch the latest market news items tagged with related symbols and
     * sectors. `headline_ar` falls back to the English headline when the
     * source has no Arabic edition.
     *
     * @return list<array{
     *     headline: string,
     *     headline_ar: string,
     *     source: string,
     *     url: ?string,
     *     minutes: int,
     *     symbols: ?list<string>,
     *     sectors: ?list<string>,
     *     published_at: CarbonInterface
     * }>
     */
    public function fetchLatest(): array;
}
