<?php

namespace App\Enums;

enum LoginEventType: string
{
    case Login = 'login';
    case Logout = 'logout';
    // Every unclean exit (window close, power loss, crash, force-kill) is
    // detected on the next boot and recorded as ApplicationCrash — the causes
    // are indistinguishable after the fact. See SessionManager::markUncleanExit.
    case ApplicationCrash = 'application_crash';
    case ForcedLogout = 'forced_logout';
}
