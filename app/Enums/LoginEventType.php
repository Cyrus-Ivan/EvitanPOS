<?php

namespace App\Enums;

enum LoginEventType: string
{
    case Login = 'login';
    case Logout = 'logout';
    case ApplicationCrash = 'application_crash';
    case ForcedLogout = 'forced_logout';

    // Legacy values retained so historical rows recorded before the boot-guard
    // model still resolve; no new rows are written with these types.
    case WindowClosed = 'window_closed';
    case PowerLoss = 'power_loss';
}
