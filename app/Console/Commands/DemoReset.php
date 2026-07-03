<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\User;
use Database\Seeders\DemoPortfolioSeeder;
use Database\Seeders\InstitutionSeeder;
use Database\Seeders\NewsSeeder;
use Illuminate\Console\Command;

class DemoReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:reset {--fresh : Delete the demo user first for a completely clean rebuild}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild the golden demo portfolio (institutions, demo user, sync, analysis, health history) in one shot';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('fresh')) {
            $deleted = User::whereIn('email', ['demo@mahafeth.test', 'sara@mahafeth.test'])->delete();
            $this->components->info($deleted ? 'Existing demo users removed.' : 'No existing demo users found.');
        }

        $this->components->task('Seeding institutions', function (): void {
            (new InstitutionSeeder)->run();
        });

        $this->components->task('Seeding market news', function (): void {
            (new NewsSeeder)->run();
        });

        $this->components->task('Building demo portfolio (connections, sync, analysis, history)', function (): void {
            (new DemoPortfolioSeeder)->run();
        });

        $this->components->task('Clearing stale AI insights', function (): void {
            AiInsight::whereHas(
                'portfolioSnapshot.user',
                fn ($query) => $query->whereIn('email', ['demo@mahafeth.test', 'sara@mahafeth.test']),
            )->delete();
        });

        $this->components->info('Demo ready: demo@mahafeth.test (tech-heavy) and sara@mahafeth.test (conservative) / password');

        return self::SUCCESS;
    }
}
