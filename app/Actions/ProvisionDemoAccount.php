<?php

namespace App\Actions;

use App\Enums\ConsentStatus;
use App\Enums\RiskTolerance;
use App\Enums\TimeHorizon;
use App\Models\Institution;
use App\Models\User;
use App\Services\Analytics\PortfolioAnalyzer;
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
        private SyncConnection $syncConnection,
        private PortfolioAnalyzer $analyzer,
    ) {}

    public function handle(): User
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

        foreach (Institution::whereIn('slug', ['derayah', 'alrajhi-capital', 'rain'])->get() as $institution) {
            $connection = $user->connections()->create(['institution_id' => $institution->id]);

            $user->consents()->create([
                'institution_id' => $institution->id,
                'connection_id' => $connection->id,
                'scopes' => config('mahafeth.consent_scopes'),
                'status' => ConsentStatus::Active,
                'granted_at' => now(),
                'expires_at' => now()->addDays((int) config('mahafeth.consent_ttl_days')),
            ]);

            $this->syncConnection->handle($connection);
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
        $seeder = new DemoPortfolioSeeder;
        $seeder->backfillSnapshotHistory($user);
        $this->analyzer->analyze($user->fresh());
        $seeder->backfillHealthHistory($user);

        return $user;
    }
}
