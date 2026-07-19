<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * Applies the stored business timezone to the running app. Timestamps are
 * stored in local app time, so the timezone is meant to be chosen once during
 * initial setup — changing it later only shifts how existing timestamps are
 * displayed and the local time of new writes; it does not rewrite history.
 *
 * When no setting row exists (fresh install pre-wizard, or an upgrade from an
 * install that predates the setting) the APP_TIMEZONE config value stays in
 * effect unchanged.
 */
class ApplyBusinessTimezone
{
    public function __invoke(): void
    {
        $timezone = Setting::where('key', 'timezone')->value('value');

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
