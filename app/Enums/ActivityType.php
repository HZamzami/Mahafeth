<?php

namespace App\Enums;

enum ActivityType: string
{
    case AlertRaised = 'alert_raised';
    case ScoreDropAlerted = 'score_drop_alerted';
    case ScoreChanged = 'score_changed';
    case ConnectionSynced = 'connection_synced';
    case ConnectionDisconnected = 'connection_disconnected';
    case InsightGenerated = 'insight_generated';
    case GoalSaved = 'goal_saved';
    case GoalDeleted = 'goal_deleted';
    case ConsentGranted = 'consent_granted';
    case LoggedIn = 'logged_in';
    case LoggedOut = 'logged_out';
    case PasswordChanged = 'password_changed';

    public function category(): ActivityCategory
    {
        return match ($this) {
            self::AlertRaised,
            self::ScoreDropAlerted => ActivityCategory::Notifications,

            self::ScoreChanged,
            self::ConnectionSynced,
            self::ConnectionDisconnected,
            self::InsightGenerated,
            self::GoalSaved,
            self::GoalDeleted => ActivityCategory::Portfolio,

            self::ConsentGranted,
            self::LoggedIn,
            self::LoggedOut,
            self::PasswordChanged => ActivityCategory::Security,
        };
    }

    /**
     * The event line as the user reads it, rendered in the current locale
     * from the stored raw params (same pattern as AlertEvaluator keys).
     *
     * @param  array<string, mixed>  $params
     */
    public function label(array $params): string
    {
        return match ($this) {
            // Alert events store the alert's own translation key + params.
            self::AlertRaised => __($params['key'] ?? '', $params['params'] ?? []),
            self::ScoreDropAlerted => __('Your health score dropped from :from to :to.', $params),
            self::ScoreChanged => __('Health score moved from :from to :to after a new analysis.', $params),
            self::ConnectionSynced => __(':institution synced — :count holdings imported.', $params),
            self::ConnectionDisconnected => __(':institution was disconnected and its consent revoked.', $params),
            self::InsightGenerated => __('A new AI insight report was generated for your portfolio.'),
            self::GoalSaved => __('Goal ":name" was saved.', $params),
            self::GoalDeleted => __('Goal ":name" was deleted.', $params),
            self::ConsentGranted => __('You granted :institution access to your data until :expires.', $params),
            self::LoggedIn => __('Signed in from :ip.', $params),
            self::LoggedOut => __('Signed out.'),
            self::PasswordChanged => __('Your password was changed.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::AlertRaised, self::ScoreDropAlerted => 'exclamation-triangle',
            self::ScoreChanged => 'chart-bar',
            self::ConnectionSynced => 'arrow-path',
            self::ConnectionDisconnected => 'link-slash',
            self::InsightGenerated => 'sparkles',
            self::GoalSaved, self::GoalDeleted => 'flag',
            self::ConsentGranted => 'shield-check',
            self::LoggedIn, self::LoggedOut => 'key',
            self::PasswordChanged => 'lock-closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AlertRaised, self::ScoreDropAlerted => 'red',
            self::ConnectionDisconnected, self::GoalDeleted => 'amber',
            self::ConsentGranted, self::InsightGenerated => 'emerald',
            default => 'zinc',
        };
    }
}
