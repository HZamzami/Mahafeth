<?php

namespace Database\Seeders;

use App\Actions\SyncConnection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Illuminate\Database\Seeder;

class DemoPortfolioSeeder extends Seeder
{
    /**
     * Seed a demo investor connected to every institution, with a deliberately
     * imperfect portfolio (tech-heavy, oversized Apple position) so all
     * analytics have findings to surface.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@mahafeth.test'],
            [
                'name' => 'Demo Investor',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $syncConnection = app(SyncConnection::class);

        Institution::all()->each(function (Institution $institution) use ($user, $syncConnection): void {
            $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);

            $syncConnection->handle($connection);
        });

        $this->backfillSnapshotHistory($user);

        app(PortfolioAnalyzer::class)->analyze($user);
    }

    /**
     * Create weekly snapshots for the trailing six months so trend charts
     * have history from day one. Only today's snapshot (written by the
     * analyzer) carries full metrics.
     */
    private function backfillSnapshotHistory(User $user): void
    {
        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subMonths(6));
        $values = app(ReturnCalculator::class)->portfolioValueSeries($data['priceSeries'], $data['quantities']);

        $weekly = array_slice($values, 0, null, true);
        $index = 0;

        foreach ($weekly as $date => $value) {
            if ($index++ % 5 === 0 && $date !== today()->toDateString()) {
                $user->portfolioSnapshots()->updateOrCreate(
                    ['as_of' => $date],
                    ['total_value' => $value],
                );
            }
        }
    }
}
