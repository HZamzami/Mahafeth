<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent when the nightly re-analysis surfaces new threshold alerts or a
 * meaningful health-score drop: email always, web push on devices the user
 * subscribed. Renders in the user's preferred locale via the
 * HasLocalePreference contract on the User model.
 */
class PortfolioAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<array{key: string, color: string, params: array<string, string>}>  $newAlerts
     */
    public function __construct(
        public array $newAlerts,
        public ?int $scoreDrop = null,
        public bool $pushOnly = false,
    ) {}

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->pushOnly ? [] : ['mail'];

        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $lines = array_map(
            fn (array $alert): string => __($alert['key'], $alert['params']),
            $this->newAlerts,
        );

        if ($this->scoreDrop !== null) {
            array_unshift($lines, __('Your Portfolio Health Score dropped by :points points.', ['points' => $this->scoreDrop]));
        }

        return (new WebPushMessage)
            ->title(__('Mahafeth: your portfolio needs attention'))
            ->body(implode("\n", $lines))
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('portfolio-alert')
            ->data(['url' => route('dashboard')]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Mahafeth: your portfolio needs attention'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The latest analysis of your unified portfolio surfaced the following:'));

        if ($this->scoreDrop !== null) {
            $message->line(__('Your Portfolio Health Score dropped by :points points.', ['points' => $this->scoreDrop]));
        }

        foreach ($this->newAlerts as $alert) {
            $message->line(__($alert['key'], $alert['params']));
        }

        return $message
            ->action(__('Open Dashboard'), route('dashboard'))
            ->line(__('You can turn these notifications off in your profile settings.'));
    }
}
