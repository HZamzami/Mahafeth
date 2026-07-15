<?php

namespace App\Jobs;

use App\Actions\ProvisionDemoAccount;
use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\AiInsight;
use App\Models\User;
use App\Notifications\PortfolioAlertNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\PortfolioAnalyzer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzePortfolioJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A deleted account silently discards its queued analysis instead of
     * failing the job with a missing-model exception.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user) {}

    /**
     * Only one analysis per user should be queued at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(PortfolioAnalyzer $analyzer, AlertEvaluator $alertEvaluator): void
    {
        $previous = $this->user->latestSnapshot();
        $previousAlerts = $alertEvaluator->forUser($this->user, $previous);

        $snapshot = $analyzer->analyze($this->user);

        if ($snapshot === null) {
            return;
        }

        // Diff on identity, not key: custom rules share sentence templates,
        // so two different rules must still count as distinct alerts.
        $alerts = $alertEvaluator->forUser($this->user, $snapshot);
        $newAlerts = array_values(array_filter(
            $alerts,
            fn (array $alert): bool => ! in_array($alert['identity'], array_column($previousAlerts, 'identity'), true),
        ));

        // The activity feed logs every raised alert and score drop, even
        // when the user opted out of the emails.
        foreach ($newAlerts as $alert) {
            ActivityEvent::record($this->user, ActivityType::AlertRaised, [
                'key' => $alert['key'],
                'params' => $alert['params'],
            ]);
        }

        // Alerts that stopped firing are worth celebrating too: the user
        // acted (or the market did) and the issue cleared. Custom alerts
        // only count while their rule is still enabled, so switching a
        // rule off is not mistaken for progress.
        if ($previous !== null) {
            $enabledRuleIds = $this->user->alertRules()->where('enabled', true)->pluck('id')->all();
            $activeIdentities = array_column($alerts, 'identity');

            $resolved = array_filter($previousAlerts, function (array $alert) use ($activeIdentities, $enabledRuleIds): bool {
                if (in_array($alert['identity'], $activeIdentities, true)) {
                    return false;
                }

                return ! str_starts_with($alert['identity'], 'custom:')
                    || in_array((int) substr($alert['identity'], 7), $enabledRuleIds, true);
            });

            foreach ($resolved as $alert) {
                ActivityEvent::record($this->user, ActivityType::AlertResolved, [
                    'key' => $alert['key'],
                    'params' => $alert['params'],
                ]);
            }
        }

        $drop = $previous !== null && $previous->health_score !== null && $snapshot->health_score !== null
            ? $previous->health_score - $snapshot->health_score
            : 0;
        $threshold = (int) config('mahafeth.alert_score_drop_threshold');

        if ($drop >= $threshold) {
            ActivityEvent::record($this->user, ActivityType::ScoreDropAlerted, [
                'from' => $previous->health_score,
                'to' => $snapshot->health_score,
            ]);
        }

        // Keep the AI insight in step with the fresh analysis, but only for
        // users who have generated one before: pre-generating for accounts
        // that never opened the feature would spend a model call per user
        // per night for nothing. Demo accounts get theirs at provisioning.
        if (! str_ends_with($this->user->email, '@'.ProvisionDemoAccount::EMAIL_DOMAIN)
            && AiInsight::whereIn('portfolio_snapshot_id', $this->user->portfolioSnapshots()->select('id'))->exists()) {
            GenerateInsightsJob::request($this->user, $this->user->preferredLocale());
        }

        // Interactive flows call the analyzer directly; only this background
        // path notifies, and never on the very first analysis.
        if ($previous === null || ! $this->user->notify_alerts) {
            return;
        }

        if ($newAlerts !== [] || $drop >= $threshold) {
            $this->user->notify(new PortfolioAlertNotification(
                $newAlerts,
                $drop >= $threshold ? $drop : null,
            ));
        }
    }
}
