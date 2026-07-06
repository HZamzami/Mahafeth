<?php

namespace Database\Seeders;

use App\Actions\ImportHoldings;
use App\Actions\SyncConnection;
use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DemoPortfolioSeeder extends Seeder
{
    /**
     * Seed a demo investor connected to every institution, with a deliberately
     * imperfect portfolio (tech-heavy, oversized Apple position) so all
     * analytics have findings to surface.
     */
    public function run(): void
    {
        // Persona 1: tech-heavy risk-taker with a Balanced profile — every
        // analyzer has findings (including a non-compliant JPM position for
        // the Shariah screen), and the health score suffers for it. His
        // Alinma Capital equities arrive via the statement import flow.
        $demo = $this->persona(
            'demo@mahafeth.test',
            'Faisal Alqahtani',
            RiskTolerance::Balanced,
            Institution::whereIn('slug', ['alinma-bank', 'derayah', 'rain'])->get(),
        );

        // His Alinma Capital equities arrive through the statement import
        // path, exactly as they would in the live demo.
        app(ImportHoldings::class)->handle(
            $demo,
            Institution::where('slug', 'alinma-capital')->firstOrFail(),
            [
                ['symbol' => '2222.SR', 'quantity' => 800.0, 'avg_cost' => 8.10],
                ['symbol' => '7010.SR', 'quantity' => 500.0, 'avg_cost' => 10.40],
                ['symbol' => '1010.SR', 'quantity' => 600.0, 'avg_cost' => 24.00],
            ],
        );

        $this->backfillSnapshotHistory($demo);
        app(PortfolioAnalyzer::class)->analyze($demo->fresh());
        $this->backfillHealthHistory($demo);

        // Persona 2: a calmer investor holding only local Saudi equities,
        // whose portfolio volatility actually matches her Balanced profile —
        // the healthy contrast to the demo investor.
        $sara = $this->persona(
            'sara@mahafeth.test',
            'Sara Almutairi',
            RiskTolerance::Balanced,
            Institution::where('slug', 'alrajhi-capital')->get(),
        );

        app(PortfolioAnalyzer::class)->analyze($sara->fresh());
    }

    /**
     * Create a demo user connected and synced to the given institutions,
     * with a risk profile for the given tolerance band.
     *
     * @param  Collection<int, Institution>  $institutions
     */
    private function persona(string $email, string $name, RiskTolerance $tolerance, $institutions): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $syncConnection = app(SyncConnection::class);

        $institutions->each(function (Institution $institution) use ($user, $syncConnection): void {
            $connection = $user->connections()->firstOrCreate(['institution_id' => $institution->id]);

            $syncConnection->handle($connection);
        });

        $user->riskProfile()->updateOrCreate([], [
            'answers' => ['horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'liquidity' => 3, 'target_return' => 2, 'shariah' => 1],
            'risk_tolerance' => $tolerance,
            'time_horizon' => TimeHorizon::Long,
            'target_return' => $tolerance->targetReturn(),
            'target_volatility' => $tolerance->targetVolatility(),
            'liquidity_needs' => 'moderate',
            'constraints' => ['shariah_required' => true, 'shariah_preferred' => false],
        ]);

        return $user;
    }

    /**
     * Give the historical snapshots a plausible health trajectory converging
     * on today's real score, so the trend chart has a story to tell. The
     * history is synthetic — like the rest of the demo data.
     */
    private function backfillHealthHistory(User $user): void
    {
        $currentScore = $user->latestSnapshot()?->health_score;

        if ($currentScore === null) {
            return;
        }

        $snapshots = $user->portfolioSnapshots()
            ->whereNull('health_score')
            ->orderByDesc('as_of')
            ->get();

        foreach ($snapshots as $index => $snapshot) {
            $drift = (int) floor(($index + 1) / 3);
            $wiggle = ($index * 7) % 5 - 2;

            $snapshot->update([
                'health_score' => max(0, min(100, $currentScore + $drift + $wiggle)),
            ]);
        }
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
