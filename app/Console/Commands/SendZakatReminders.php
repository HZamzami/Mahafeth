<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ZakatReminderNotification;
use App\Support\HijriDate;
use Illuminate\Console\Command;

/**
 * Reminds users whose Hijri zakat anniversary completes within the
 * reminder window, once per occurrence.
 */
class SendZakatReminders extends Command
{
    protected $signature = 'mahafeth:zakat-reminders';

    protected $description = 'Notify users whose zakat hawl completes within the reminder window';

    public function handle(): int
    {
        $windowDays = (int) config('mahafeth.zakat.reminder_days');
        $sent = 0;

        $users = User::whereNotNull('zakat_hawl_month')
            ->whereNotNull('zakat_hawl_day')
            ->where('notify_alerts', true)
            ->get();

        foreach ($users as $user) {
            $hawlDate = HijriDate::nextGregorian($user->zakat_hawl_month, $user->zakat_hawl_day);

            if (today()->diffInDays($hawlDate) > $windowDays) {
                continue;
            }

            if ($user->zakat_last_reminded_for?->isSameDay($hawlDate)) {
                continue;
            }

            $zakat = $user->latestSnapshot()?->metrics['zakat'] ?? null;

            $user->notify(new ZakatReminderNotification(
                $hawlDate,
                $zakat['zakat_due'] ?? null,
                (bool) ($zakat['below_nisab'] ?? false),
            ));

            $user->forceFill(['zakat_last_reminded_for' => $hawlDate->toDateString()])->save();
            $sent++;
        }

        $this->components->info("Sent {$sent} zakat reminders.");

        return self::SUCCESS;
    }
}
