<?php

namespace App\Enums;

enum LoginEventType: string
{
    case Login = 'login';
    case Logout = 'logout';
    case WindowClosed = 'window_closed';
    case ApplicationCrash = 'application_crash';
    case PowerLoss = 'power_loss';
    case ForcedLogout = 'forced_logout';

    /**
     * Whether this event leaves a session "open" (i.e. not cleanly terminated).
     * Only a bare Login leaves a session open; every other event terminates it.
     * Used by session reconciliation to detect an un-terminated prior session.
     */
    public function isSessionOpen(): bool
    {
        return $this === self::Login;
    }
}
