<?php

namespace App\Support\Audit;

/**
 * Reduces a before/after settings map to only the keys that actually changed,
 * so a settings.updated audit row captures the meaningful diff rather than the
 * full configuration. Returns null when nothing changed (skip the audit row).
 */
class SettingsDiff
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    public static function changed(array $before, array $after): ?array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            if ($previous !== $value) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return $new === [] ? null : ['old' => $old, 'new' => $new];
    }
}
