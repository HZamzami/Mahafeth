<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
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
        $previousAlerts = $alertEvaluator->evaluate($previous?->metrics, $this->user->riskProfile);

        $snapshot = $analyzer->analyze($this->user);

        if ($snapshot === null) {
            return;
        }

        $alerts = $alertEvaluator->evaluate($snapshot->metrics, $this->user->riskProfile);
        $newAlerts = array_values(array_filter(
            $alerts,
            fn (array $alert): bool => ! in_array($alert['key'], array_column($previousAlerts, 'key'), true),
        ));

        // The activity feed logs every raised alert and score drop, even
        // when the user opted out of the emails.
        foreach ($newAlerts as $alert) {
            ActivityEvent::record($this->user, ActivityType::AlertRaised, [
                'key' => $alert['key'],
                'params' => $alert['params'],
            ]);
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
