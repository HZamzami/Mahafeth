<?php

namespace App\Actions;

use App\Enums\ConsentStatus;
use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Jobs\GenerateInsightsJob;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
use App\Services\Prices\SimulatedPriceProvider;
use Database\Seeders\DemoPortfolioSeeder;
use Database\Seeders\InstitutionSeeder;
use Illuminate\Support\Str;

/**
 * One-tap demo: a throwaway pre-analyzed account so a visitor experiences
 * the intelligence layer without registering or connecting anything. The
 * portfolio mirrors the golden demo persona — tech-tilted with a crypto
 * sleeve and a non-compliant contrast position — so every analyzer has
 * findings to show. Accounts live on a purgeable domain and are removed
 * by mahafeth:purge-demo-accounts after 48 hours.
 */
class ProvisionDemoAccount
{
    public const EMAIL_DOMAIN = 'demo.mahafeth.test';

    public function __construct(
        private PortfolioAnalyzer $analyzer,
    ) {}

    public function handle(): User
    {
        // Demo holdings are synthetic, so live price fetching (which sleeps
        // between provider requests and would hold this request open for a
        // minute or more on production) adds nothing: build this run's sync
        // chain on the simulated provider explicitly, leaving the global
        // env-driven binding untouched.
        $syncConnection = app()->make(SyncConnection::class, [
            'syncPrices' => new SyncPrices(app(SimulatedPriceProvider::class)),
        ]);

        return $this->provision($syncConnection);
    }

    private function provision(SyncConnection $syncConnection): User
    {
        // Institutions are seeded in dev but not guaranteed in prod; the
        // seeder is idempotent updateOrCreate.
        (new InstitutionSeeder)->run();

        $user = User::create([
            'name' => __('Guest Investor'),
            'email' => 'guest-'.Str::lower(Str::random(16)).'@'.self::EMAIL_DOMAIN,
            'password' => Str::password(32),
        ]);

        // Not mass-assignable on purpose; the demo account is born verified.
        $user->forceFill(['email_verified_at' => now()])->save();

        // alinma-bank contributes the cash sleeve and the deposit
        // transactions the contribution-vs-growth split reads.
        foreach (Institution::whereIn('slug', ['alinma-bank', 'derayah', 'alrajhi-capital', 'rain'])->get() as $institution) {
            $connection = $user->connections()->create(['institution_id' => $institution->id]);

            $user->consents()->create([
                'institution_id' => $institution->id,
                'connection_id' => $connection->id,
                'scopes' => config('mahafeth.consent_scopes'),
                'status' => ConsentStatus::Active,
                'granted_at' => now(),
                'expires_at' => now()->addDays((int) config('mahafeth.consent_ttl_days')),
            ]);

            $syncConnection->handle($connection);
        }

        $user->riskProfile()->create([
            'answers' => ['age' => 2, 'horizon' => 3, 'goal' => 3, 'drop_reaction' => 3, 'experience' => 2, 'liquidity' => 3, 'target_return' => 2, 'contributions' => 1, 'base_currency' => 1, 'shariah' => 1],
            'risk_tolerance' => RiskTolerance::Balanced,
            'time_horizon' => TimeHorizon::Long,
            'target_return' => RiskTolerance::Balanced->targetReturn(),
            'target_volatility' => RiskTolerance::Balanced->targetVolatility(),
            'liquidity_needs' => 'moderate',
            'constraints' => ['shariah_required' => true, 'shariah_preferred' => false, 'base_currency' => 'SAR', 'contribution_frequency' => 'monthly'],
        ]);

        // Weekly history plus a plausible health trajectory, so the trend
        // chart and week-over-week comparisons work from the first minute.
        // The showcase state (plan, hawl, purification ledger, resolved
        // alert) goes in before the analyzer so the snapshot reflects it,
        // and the previous snapshot is enriched after so daily-move
        // attribution has a real starting point.
        $seeder = new DemoPortfolioSeeder;
        $seeder->backfillSnapshotHistory($user);
        $seeder->seedShowcaseState($user);
        $this->analyzer->analyze($user->fresh());
        $seeder->backfillHealthHistory($user);
        $seeder->enrichPreviousSnapshot($user->fresh());

        // Pre-generate the AI insight so the advisor and dashboard cards
        // are ready (or already spinning) by the time the visitor looks.
        if ($user->latestSnapshot() !== null) {
            GenerateInsightsJob::request($user, app()->getLocale());
        }

        return $user;
    }
}
