<?php

namespace App\Enums;

enum ActivityCategory: string
{
    case Notifications = 'notifications';
    case Portfolio = 'portfolio';
    case Security = 'security';

    public function label(): string
    {
        return match ($this) {
            self::Notifications => __('Notifications'),
            self::Portfolio => __('Portfolio Changes'),
            self::Security => __('Security & Consents'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Notifications => 'bell',
            self::Portfolio => 'arrow-path',
            self::Security => 'shield-check',
        };
    }

    public function emptyState(): string
    {
        return match ($this) {
            self::Notifications => __('Risk alerts and score warnings will show up here as they are raised.'),
            self::Portfolio => __('Syncs, score changes, goals, and generated insights will be logged here.'),
            self::Security => __('Logins, password changes, and Open Banking consents will be logged here.'),
        };
    }

    /**
     * @return list<ActivityType>
     */
    public function types(): array
    {
        return array_values(array_filter(
            ActivityType::cases(),
            fn (ActivityType $type): bool => $type->category() === $this,
        ));
    }
}
