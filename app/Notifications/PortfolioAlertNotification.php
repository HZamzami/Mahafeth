<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed when the nightly re-analysis surfaces new threshold alerts or a
 * meaningful health-score drop. Renders in the user's preferred locale via
 * the HasLocalePreference contract on the User model.
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
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
