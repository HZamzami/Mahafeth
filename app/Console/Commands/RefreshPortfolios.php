<?php

namespace App\Console\Commands;

use App\Actions\SyncConnection;
use App\Enums\ConnectionStatus;
use App\Jobs\AnalyzePortfolioJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshPortfolios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:refresh-portfolios';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-sync every connected API connection and queue a fresh portfolio analysis per user';

    /**
     * Execute the console command.
     */
    public function handle(SyncConnection $syncConnection): int
    {
        $users = User::whereHas('connections', fn ($query) => $query
            ->where('status', ConnectionStatus::Connected))
            ->with(['connections' => fn ($query) => $query
                ->where('status', ConnectionStatus::Connected)
                ->where('source', 'api')])
            ->get();

        foreach ($users as $user) {
            foreach ($user->connections as $connection) {
                try {
                    $syncConnection->handle($connection);
                } catch (\Throwable $exception) {
                    Log::warning('Scheduled connection sync failed.', [
                        'connection_id' => $connection->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            AnalyzePortfolioJob::dispatch($user);
        }

        $this->components->info("Refreshed {$users->count()} portfolios.");

        return self::SUCCESS;
    }
}
