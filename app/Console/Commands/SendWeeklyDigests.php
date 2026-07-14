<?php

namespace App\Console\Commands;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use App\Services\Analytics\AlertEvaluator;
use Illuminate\Console\Command;

class SendWeeklyDigests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mahafeth:send-weekly-digest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send each opted-in user a week-in-review of their health score, value, and alerts';

    /**
     * Execute the console command.
     */
    public function handle(AlertEvaluator $alerts): int
    {
        $users = User::where('notify_alerts', true)
            ->where('email', 'not like', '%@demo.mahafeth.test')
            ->whereHas('portfolioSnapshots')
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $latest = $user->latestSnapshot();

            /** @var ?PortfolioSnapshot $weekAgo */
            $weekAgo = $user->portfolioSnapshots()
                ->where('as_of', '<=', $latest->as_of->copy()->subDays(6))
                ->latest('as_of')
                ->first();

            if ($weekAgo === null) {
                continue;
            }

            $user->notify(new WeeklyDigestNotification(
                healthScore: $latest->health_score,
                scoreChange: $latest->health_score !== null && $weekAgo->health_score !== null
                    ? $latest->health_score - $weekAgo->health_score
                    : null,
                totalValue: (float) $latest->total_value,
                valueChange: (float) $latest->total_value - (float) $weekAgo->total_value,
                activeAlerts: count($alerts->forUser($user, $latest)),
            ));

            $sent++;
        }

        $this->info("Sent {$sent} weekly digests.");

        return self::SUCCESS;
    }
}
