<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The Sunday-morning week-in-review: how the health score and total value
 * moved over the past week, plus how many alerts are currently active.
 * Email always, web push on subscribed devices, rendered in the user's
 * preferred locale via the HasLocalePreference contract.
 */
class WeeklyDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ?int $healthScore,
        public ?int $scoreChange,
        public float $totalValue,
        public float $valueChange,
        public int $activeAlerts,
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
        $lines = [];

        if ($this->healthScore !== null) {
            $lines[] = match (true) {
                $this->scoreChange === null, $this->scoreChange === 0 => __('Your Portfolio Health Score held steady at :score.', ['score' => $this->healthScore]),
                $this->scoreChange > 0 => __('Your Portfolio Health Score rose :points points this week to :score.', ['points' => $this->scoreChange, 'score' => $this->healthScore]),
                default => __('Your Portfolio Health Score fell :points points this week to :score.', ['points' => abs($this->scoreChange), 'score' => $this->healthScore]),
            };
        }

        $lines[] = $this->valueChange >= 0
            ? __('Your portfolio is worth :value, up :change over the week.', ['value' => "\u{20C1} ".Number::format($this->totalValue, 0), 'change' => "\u{20C1} ".Number::format($this->valueChange, 0)])
            : __('Your portfolio is worth :value, down :change over the week.', ['value' => "\u{20C1} ".Number::format($this->totalValue, 0), 'change' => "\u{20C1} ".Number::format(abs($this->valueChange), 0)]);

        $lines[] = $this->activeAlerts === 0
            ? __('No risk alerts are active right now.')
            : trans_choice('{1} :count risk alert is active right now.|[2,*] :count risk alerts are active right now.', $this->activeAlerts, ['count' => $this->activeAlerts]);

        return $lines;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your Mahafeth week in review'))
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
            ->title(__('Your Mahafeth week in review'))
            ->body(implode("\n", $this->lines()))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('weekly-digest')
            ->data(['url' => route('dashboard')]);
    }
}
