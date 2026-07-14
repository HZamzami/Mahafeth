<?php

namespace App\Console\Commands;

use App\Actions\ProvisionDemoAccount;
use App\Models\User;
use Illuminate\Console\Command;

class PurgeDemoAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:purge-demo-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete throwaway guest demo accounts older than 48 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stale = User::where('email', 'like', '%@'.ProvisionDemoAccount::EMAIL_DOMAIN)
            ->where('created_at', '<', now()->subHours(48))
            ->get();

        // Model deletes so relation cleanup behaves exactly like the
        // in-app delete-account flow.
        $stale->each->delete();

        $this->info("Purged {$stale->count()} demo accounts.");

        return self::SUCCESS;
    }
}
