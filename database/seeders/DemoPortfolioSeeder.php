<?php

namespace Database\Seeders;

use App\Actions\ImportHoldings;
use App\Actions\SyncConnection;
use App\Enums\ActivityType;
use App\Enums\ConsentStatus;
use App\Enums\ObligationKind;
use App\Enums\RiskTolerance;
use App\Enums\ShariahStatus;
use App\Enums\TimeHorizon;
use App\Enums\TransactionType;
use App\Models\ActivityEvent;
use App\Models\Institution;
use App\Models\InvestmentPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Analytics\InvestmentPlanBuilder;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Analytics\PortfolioDataAssembler;
use App\Services\Analytics\ReturnCalculator;
use App\Services\Fx\FxRateService;
use App\Support\HijriDate;
use Carbon\CarbonInterface;
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
        // Persona 1: a broadly diversified investor with a Balanced profile
        // whose health score lands around the mid-70s — good, with real
        // findings left for the analyzers (a tech tilt, a non-compliant JPM
        // position for the Shariah screen, and a crypto sleeve). His Alinma
        // Capital equities arrive via the statement import flow.
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
                ['symbol' => '2222.SR', 'quantity' => 21000.0, 'avg_cost' => 8.10],
                ['symbol' => '1120.SR', 'quantity' => 6200.0, 'avg_cost' => 19.40],
                ['symbol' => '7010.SR', 'quantity' => 9500.0, 'avg_cost' => 10.40],
                ['symbol' => '1010.SR', 'quantity' => 600.0, 'avg_cost' => 24.00],
            ],
        );

        $demo->goals()->updateOrCreate(
            ['name' => 'Retirement'],
            [
                'target_amount' => 5_000_000,
                'target_date' => now()->addYears(15)->toDateString(),
                'monthly_contribution' => 8_000,
            ],
        );

        $this->backfillSnapshotHistory($demo);
        $this->seedShowcaseState($demo);
        app(PortfolioAnalyzer::class)->analyze($demo->fresh());
        $this->backfillHealthHistory($demo);
        $this->enrichPreviousSnapshot($demo->fresh());

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

            // Mirror the consent journey so revocation and expiry behave
            // exactly as they would for a real connection.
            $user->consents()->updateOrCreate(
                ['institution_id' => $institution->id, 'connection_id' => $connection->id],
                [
                    'scopes' => config('mahafeth.consent_scopes'),
                    'status' => ConsentStatus::Active,
                    'granted_at' => now(),
                    'expires_at' => now()->addDays((int) config('mahafeth.consent_ttl_days')),
                ],
            );

            $syncConnection->handle($connection);
        });

        $user->riskProfile()->updateOrCreate([], [
            'answers' => ['age' => 2, 'horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'experience' => 3, 'liquidity' => 3, 'target_return' => 2, 'contributions' => 1, 'base_currency' => 1, 'shariah' => 1],
            'risk_tolerance' => $tolerance,
            'time_horizon' => TimeHorizon::Long,
            'target_return' => $tolerance->targetReturn(),
            'target_volatility' => $tolerance->targetVolatility(),
            'liquidity_needs' => 'moderate',
            'constraints' => ['shariah_required' => true, 'shariah_preferred' => false, 'base_currency' => 'SAR', 'contribution_frequency' => 'monthly'],
        ]);

        return $user;
    }

    /**
     * Give the historical snapshots a plausible health trajectory converging
     * on today's real score, so the trend chart has a story to tell. The
     * history is synthetic — like the rest of the demo data.
     */
    public function backfillHealthHistory(User $user): void
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
     * State the showcase features need, seeded before the analyzer runs so
     * the snapshot reflects it: an investment plan (drift), a zakat hawl
     * date (countdown), a past purification settlement (ledger story), and
     * one resolved alert (follow-through moment). Like the health history,
     * parts of this are demo storytelling.
     */
    public function seedShowcaseState(User $user): void
    {
        // A real optimizer plan: its targets differ from the tech-heavy
        // actual weights, so the drift alert fires naturally and the
        // Investment Plan page is populated for the tour.
        $totalValue = $this->currentPortfolioValue($user);
        $plan = app(InvestmentPlanBuilder::class)->build($user, max(100_000.0, round($totalValue, -3)), 8_000.0);

        if ($plan !== null) {
            InvestmentPlan::updateOrCreate(
                ['user_id' => $user->id],
                ['amount' => max(100_000.0, round($totalValue, -3)), 'monthly_contribution' => 8_000.0, ...$plan],
            );
        }

        // Hawl completes in ten days: close enough for an urgent countdown,
        // far enough that the reminder window story still makes sense.
        $hawl = HijriDate::toHijri(today()->addDays(10));
        $user->forceFill([
            'zakat_hawl_month' => $hawl['month'],
            'zakat_hawl_day' => $hawl['day'],
        ])->save();

        // Purified 100 days ago for the impure income known at the time, so
        // the ledger shows history and only the newer dividend is owed.
        $settledThrough = now()->subDays(100);
        $priorImpureIncome = $this->impureDividendsBefore($user, $settledThrough);

        if ($priorImpureIncome > 0) {
            $user->obligationSettlements()->updateOrCreate(
                ['kind' => ObligationKind::Purification, 'settled_through' => $settledThrough->toDateString()],
                ['amount' => round($priorImpureIncome, 2)],
            );
        }

        // One acted-on alert from yesterday, so the dashboard opens with a
        // "nice work" moment alongside the live warnings. It is a
        // concentration alert on the (now trimmed) oversized Apple position:
        // a type the demo is not currently firing, so it never collides with
        // a live warning of the same kind.
        $resolved = ActivityEvent::record($user, ActivityType::AlertResolved, [
            'key' => 'Concentration alert: :name is :weight of your portfolio — above the :threshold threshold.',
            'params' => ['name' => 'Apple', 'weight' => '34.0%', 'threshold' => '30%'],
        ]);
        $resolved->forceFill(['created_at' => now()->subDay()])->save();
    }

    /**
     * Rewrite the most recent pre-today snapshot with real per-holding
     * valuation state as of its date (and slightly shifted component
     * scores), so daily-move attribution and the "what changed" strip work
     * from the first minute instead of the second day.
     */
    public function enrichPreviousSnapshot(User $user): void
    {
        $today = $user->latestSnapshot();
        $previous = $user->portfolioSnapshots()
            ->where('as_of', '<', today()->toDateString())
            ->orderByDesc('as_of')
            ->first();

        if ($today?->metrics === null || $previous === null) {
            return;
        }

        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subMonths(6));

        // The weekly backfill can land on the very date of the latest close,
        // which would make the two snapshots identical and the daily move a
        // flat 0.00%. Value the previous snapshot at least one trading day
        // behind the freshest price instead.
        $latestPriceDate = collect($data['priceSeries'])
            ->map(fn (array $series): ?string => array_key_last($series))
            ->filter()
            ->max();
        $asOf = min($previous->as_of->toDateString(), $latestPriceDate);
        $holdings = [];
        $total = 0.0;

        foreach ($data['priceSeries'] as $symbol => $series) {
            $closes = array_filter($series, fn (string $date): bool => $date < $asOf, ARRAY_FILTER_USE_KEY);

            if ($closes === []) {
                continue;
            }

            $baseClose = end($closes);
            $rate = $data['fxRates'][$symbol] ?? 1.0;
            $quantity = $data['quantities'][$symbol] ?? 0.0;

            $holdings[$symbol] = [
                'quantity' => $quantity,
                'native_close' => $rate > 0 ? $baseClose / $rate : $baseClose,
                'fx_rate' => $rate,
                'value' => round($quantity * $baseClose, 4),
                'weight' => 0.0,
                'currency' => $data['assets'][$symbol]['currency'] ?? config('mahafeth.base_currency'),
                'name' => $data['assets'][$symbol]['name'] ?? $symbol,
                'price_date' => array_key_last($closes),
            ];

            $total += $holdings[$symbol]['value'];
        }

        if ($holdings === [] || $total <= 0) {
            return;
        }

        foreach ($holdings as $symbol => $state) {
            $holdings[$symbol]['weight'] = $state['value'] / $total;
        }

        // Component scores a few points apart from today's, so the health
        // card has a delta to explain (the concentration driver names the
        // real largest position from today's metrics).
        $components = $today->component_scores ?? [];
        $shifted = $components;

        if (isset($shifted['concentration'])) {
            $shifted['concentration'] = min(100, $shifted['concentration'] + 12);
        }
        if (isset($shifted['performance'])) {
            $shifted['performance'] = min(100, $shifted['performance'] + 3);
        }

        $previous->update([
            'total_value' => round($total, 4),
            'metrics' => ['holdings' => $holdings],
            'component_scores' => $shifted === [] ? null : $shifted,
            'health_score' => $today->health_score !== null
                ? min(100, $today->health_score + 3)
                : $previous->health_score,
        ]);
    }

    /**
     * Current portfolio value from the latest closes, before any snapshot
     * exists.
     */
    private function currentPortfolioValue(User $user): float
    {
        $data = app(PortfolioDataAssembler::class)->forUser($user, now()->subMonths(1));
        $values = app(ReturnCalculator::class)->portfolioValueSeries($data['priceSeries'], $data['quantities']);

        return $values === [] ? 0.0 : (float) end($values);
    }

    /**
     * Base-currency dividends from non-compliant holdings executed on or
     * before the given moment: what a diligent investor would already have
     * purified.
     */
    private function impureDividendsBefore(User $user, CarbonInterface $before): float
    {
        $fx = app(FxRateService::class);

        return Transaction::with('asset')
            ->where('type', TransactionType::Dividend)
            ->where('executed_at', '<=', $before)
            ->whereHas('asset', fn ($query) => $query->where('shariah_status', ShariahStatus::NonCompliant))
            ->whereHas('account.connection', fn ($query) => $query->whereBelongsTo($user))
            ->get()
            ->sum(fn (Transaction $transaction): float => $transaction->amount * $fx->rate($transaction->asset->currency));
    }

    /**
     * Create weekly snapshots for the trailing six months so trend charts
     * have history from day one. Only today's snapshot (written by the
     * analyzer) carries full metrics.
     */
    public function backfillSnapshotHistory(User $user): void
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
