<?php

namespace App\Notifications;

use App\Support\HijriDate;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The zakat hawl is approaching: when it completes (Hijri and Gregorian)
 * and the estimated zakat due from the latest snapshot. Email always, web
 * push on subscribed devices, rendered in the user's preferred locale.
 */
class ZakatReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CarbonInterface $hawlDate,
        public ?float $zakatDue,
        public bool $belowNisab,
    ) {}

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return list<string>
     */
    protected function lines(): array
    {
        $lines = [
            __('Your zakat hawl completes on :hijri (:date).', [
                'hijri' => HijriDate::format($this->hawlDate),
                'date' => $this->hawlDate->translatedFormat('j M Y'),
            ]),
        ];

        if ($this->belowNisab) {
            $lines[] = __('Your zakatable wealth is currently below the nisab threshold, so no zakat is due unless it grows before the hawl completes.');
        } elseif ($this->zakatDue !== null) {
            $lines[] = __('Based on your latest portfolio analysis, your estimated zakat is :amount.', [
                'amount' => "\u{20C1} ".Number::format($this->zakatDue, 2),
            ]);
        }

        return $lines;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your zakat hawl is approaching'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]));

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message
            ->action(__('Open Dashboard'), route('dashboard'))
            ->line(__('You can turn these notifications off in your profile settings.'));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('Your zakat hawl is approaching'))
            ->body(implode("\n", $this->lines()))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('zakat-reminder')
            ->data(['url' => route('dashboard')]);
    }
}
