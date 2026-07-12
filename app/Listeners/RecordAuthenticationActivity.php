<?php

namespace App\Listeners;

use App\Enums\ActivityType;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Writes sign-ins, sign-outs, and password resets to the user's activity
 * feed for the Security & Consents tab.
 */
class RecordAuthenticationActivity
{
    public function handle(Login|Logout|PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        match ($event::class) {
            Login::class => ActivityEvent::record($user, ActivityType::LoggedIn, [
                'ip' => (string) request()->ip(),
                'agent' => mb_substr((string) request()->userAgent(), 0, 120),
            ]),
            Logout::class => ActivityEvent::record($user, ActivityType::LoggedOut),
            PasswordReset::class => ActivityEvent::record($user, ActivityType::PasswordChanged),
        };
    }
}
