<?php

namespace App\Jobs;

use App\Services\Markets\CompanySummaryTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Translate one company profile into Arabic off the web request. The Claude
 * call can take seconds, so running it inline would stall the instrument
 * page; the result is cached for the card to pick up on its next poll.
 */
class TranslateCompanySummaryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $symbol,
        public string $summary,
    ) {}

    public function handle(CompanySummaryTranslator $translator): void
    {
        $translator->translate($this->symbol, $this->summary);
    }
}
