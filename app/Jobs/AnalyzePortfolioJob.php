<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzePortfolioJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    /**
     * Only one analysis per user should be queued at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(PortfolioAnalyzer $analyzer): void
    {
        $analyzer->analyze($this->user);
    }
}
