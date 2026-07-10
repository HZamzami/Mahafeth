<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

interface FilingProvider
{
    /**
     * The latest company filings and disclosures.
     *
     * @return list<array{headline: string, headline_ar: string, symbol: string, type: string, source: string, url: ?string, excerpt: string, excerpt_ar: string, published_at: CarbonInterface}>
     */
    public function fetchLatest(): array;
}
