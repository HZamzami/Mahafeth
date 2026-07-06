<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Enums\ConsentStatus;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\Consent;
use Illuminate\Console\Command;

class ExpireConsents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:expire-consents';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire overdue Open Banking consents and disconnect their connections';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $overdue = Consent::with(['connection', 'user'])
            ->where('status', ConsentStatus::Active)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($overdue as $consent) {
            $consent->update(['status' => ConsentStatus::Expired]);
            $consent->connection?->update(['status' => ConnectionStatus::Disconnected]);

            AnalyzePortfolioJob::dispatch($consent->user);
        }

        $this->components->info("Expired {$overdue->count()} consents.");

        return self::SUCCESS;
    }
}
